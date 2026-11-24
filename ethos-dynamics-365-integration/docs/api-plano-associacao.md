# Camada de acesso às Custom APIs do Plano de Associação — Definição técnica

Funções PHP reutilizáveis para as Custom APIs do novo Plano de Associação
publicadas no Dynamics 365, implementadas neste plugin
(`EthosDynamics365IntegrationPlugin`). Contexto do negócio: ver
[`docs-locais/novo-plano-associacao.md`][plano] — documento sensível
mantido apenas no workspace local do site (não versionado).

Status: **implementada (v1) em `includes/association-api.php`** — leituras
validadas no sandbox (24/11/2026); funções de escrita aguardam confirmações
do fornecedor (§10). A definição agora descreve o código como está.

[plano]: ../../../docs-locais/novo-plano-associacao.md

## 0. Nomenclatura (decisões de design)

**Superfície neutra em relação ao fornecedor.** Os nomes **OData** das
actions no CRM usam o prefixo de quem as publicou (`i4d_consultar_saldo`,
`i4d_CriarTransacao`...). Esse prefixo é parte do esquema do Dynamics e não
pode ser alterado — porém **não vaza para a nossa superfície PHP**: funções,
códigos de erro, hooks e transients usam nomes de domínio (associação,
saldo, crédito, transação). Os nomes de comunicação (wire) ficam
centralizados em **constantes privadas no topo do arquivo** — o único lugar
onde o prefixo do fornecedor aparece. Se as actions forem renomeadas ou
republicadas um dia, muda-se um bloco só.

**Funções de domínio em português — exceção documentada à convenção
"EN para código".** Motivos:

1. **Espelhamento com os nomes wire**: as actions do CRM já são publicadas
   em PT (`consultar_saldo`, `obter_associadas`, `CriarTransacao`); as
   funções da camada espelham 1:1 a action que envolvem — sem tradução
   mental ao depurar contra o `$metadata` ou ler logs.
2. **Vocabulário de domínio intraduzível sem perda**: estorno (crédito) ≠
   refund (reembolso de pagamento Getnet — distinção deliberada, ver §5.3);
   parceria, associada, faixa e cortesia não têm tradução limpa.
3. **Coerência do workstream**: docs, mensagens visíveis e consumers operam
   em PT-BR; os nomes espelham os conceitos dos documentos.

Helpers técnicos de uso interno seguem em inglês (`crm_call_custom_action()`,
`crm_normalize_odata()`), alinhados ao restante do plugin
(`get_client_on_dynamics()` etc.).

## 1. Objetivo

Encapsular as Custom APIs do plano de associação em funções PHP estáveis, com
autenticação, erros, normalização e cache resolvidos uma única vez — para que
as demais implementações do novo modelo (inscrição com consumo de crédito,
área do associado, sync, checagem de vigência) **consumam contratos prontos**
em vez de reimplementar chamadas OData.

## 2. Base técnica consolidada (já validada)

- **Autenticação**: a mesma do painel (`msdyncrm_options` — OAuth 2.0 client
  credentials / Entra ID), via `get_client_on_dynamics()` do plugin.
- **Invocação**: as Custom APIs são **Actions OData (POST)**, não Functions.
  Chamada correta:
  `$client->getClient()->executeAction( $nome, $parametros )` — o SDK
  (alexacrm/dynamics-webapi-toolkit) envia o array como body JSON com
  `Content-Type: application/json`.
  - GET com parâmetros na URL retorna 400 *"cannot include key predicates"*
    (erro que motivou esta definição; diagnóstico em [plano] §7).
  - Parâmetros são **PascalCase** no CRM (`ParceriaId`, `TipoTransacao`...).
- **Erros do CRM**: vêm como HTTP 400/4xx com body
  `{"error":{"code":"0x80040265","message":"O CNPJ informado é inválido."}}`.
  O SDK lança `AlexaCRM\WebAPI\OData\ODataException` envolvendo a exceção
  Guzzle (que carrega a resposta).
- **Respostas**: vêm poluídas de anotações OData (`@odata.context`,
  `id@odata.type` etc.) que precisam ser removidas antes de uso.
- **Validação E2E**: consulta de saldo testada com HTTP 200 no sandbox
  (24/11/2026). As demais têm assinatura confirmada no `$metadata`, resposta
  ainda não observada.

## 3. Arquitetura

```
┌───────────────────────────────────────────────────────────────┐
│ Consumers (futuros): inscrição em eventos, área do associado, │
│ sync (associadas), checagem de vigência no login              │
└──────────────────────────┬────────────────────────────────────┘
                           │ funções de domínio (contratos estáveis)
┌──────────────────────────▼────────────────────────────────────┐
│ includes/association-api.php — namespace hacklabr              │
│  • consultar_saldo()          • criar_transacao_credito()     │
│  • criar_transacoes_lote()    • estornar_transacao()          │
│  • obter_associadas()         • obter_contatos_aprovados()    │
│        │ usa                                                  │
│  • crm_call_custom_action()  (invocação genérica:             │
│    bound/unbound, snake→PascalCase, extração de erro OData;   │
│    nomes wire vêm das constantes ACTION_*)                     │
│  • crm_normalize_odata()    (limpeza de anotações @odata)     │
└──────────────────────────┬────────────────────────────────────┘
                           │ executeAction (POST JSON)
┌──────────────────────────▼────────────────────────────────────┐
│ get_client_on_dynamics() → AlexaCRM WebAPI (credenciais       │
│ do painel, opção msdyncrm_options)                            │
└───────────────────────────────────────────────────────────────┘
```

**Arquivo único** (`includes/association-api.php`), exigido no bootstrap do
plugin logo após `dynamics365-data-sender.php`. Namespace `hacklabr`.

Constantes no topo do arquivo (único ponto com o prefixo do fornecedor):

```php
const ACTION_CONSULTAR_SALDO       = 'i4d_consultar_saldo';
const ACTION_CRIAR_TRANSACAO       = 'i4d_CriarTransacao';
const ACTION_CRIAR_TRANSACOES_LOTE = 'i4d_CriarTransacoesLote';
const ACTION_ESTORNAR_TRANSACAO    = 'Microsoft.Dynamics.CRM.i4d_ReembolsarTransacao'; // bound
const ACTION_OBTER_ASSOCIADAS      = 'i4d_obter_associadas';
const ACTION_OBTER_CONTATOS_APROVADOS = 'i4d_contatos_aprovados';
```

## 4. Contratos OData (confirmados no `$metadata` do sandbox)

| # | Action (wire) | Binding | Parâmetros | Retorno | Validação |
|---|---|---|---|---|---|
| 1 | `i4d_consultar_saldo` | — | `cnpj` (String, obrigatório) | `...saldoResponse` | ✅ 200 testado |
| 2 | `i4d_CriarTransacao` | — | `ParceriaId` (Guid), `Faixa` (String), `TipoTransacao` (String), `Quantidade` (Int32), `Observacao` (String), `DataTransacao` (String), `ProjetoId` (Guid, opc.), `InscricaoId` (Guid, opc.) | `...Response` | ✅ 200 testado |
| 3 | `i4d_CriarTransacoesLote` | — | `ParceriaId` (Guid), `Transacoes` (String = JSON), `Observacao` (String), `DataTransacao` (String) | `...Response` | ⬜ assinatura apenas |
| 4 | `i4d_ReembolsarTransacao` | `i4d_transacao` | `Justificativa` (String) | `...Response` | ✅ 200 testado |
| 5 | `i4d_CarregarSaldoInicial` | `fut_parceria` | (a confirmar) | `...Response` | ⬜ — **fora de escopo v1** (operação administrativa) |
| 6 | `i4d_obter_associadas` | — | `data_associacao` (DateTimeOffset, opc.), `termino_associacao` (DateTimeOffset, opc.) | `Collection(crmbaseentity)` | ⬜ assinatura apenas |
| 7 | `i4d_contatos_aprovados` | — | `contaGuid` (String) | `Collection(crmbaseentity)` | ⬜ assinatura apenas |

Notas (validadas no teste de escrita de 24/11/2026): `DataTransacao` aceita
apenas **AAAA-MM-DD** — a action recusa timestamp ISO completo com mensagem
própria ("Use o formato AAAA-MM-DD"), embora o registro gravado exiba o
timestamp completo. `TipoTransacao` aceita os códigos `i4d_id` da tabela
`i4d_tipo_transacaos` (`consumo_credito` confirmado em teste):| `i4d_id` | Nome | Coeficiente |
|---|---|---|
| `carga_inicial` | Carga de Saldo Parceria | +1 |
| `consumo_credito` | Consumo de Crédito | −1 |
| `devolucao_credito` | Devolução de Crédito | +1 |
| `atividade_gratuita` | Atividade Gratuita | 0 |
| `conversao_entrada` | Conversão de Créditos (Entradas) | +1 |
| `conversao_saida` | Conversão de Créditos (Saída) | −1 |
| `zeragem_saldo` | Zeragem de Saldo | −1 |

A transação gravada traz `i4d_idethos` (ID sequencial, ex.: `TRS-XXXXXX`),
`i4d_quantidade_convertida` (quantidade × coeficiente) e
`i4d_reembolso_realizado` (marcado como `true` na original pelo estorno,
que também cria a transação de Devolução vinculada — "Reembolso de
TRS-XXXXXX"). Actions bound (#4, #5) exigem URL
`{entitySet}({id})/Microsoft.Dynamics.CRM.{nome}`; **atenção**: o SDK
concatena `{id}` e o nome sem o separador `/` ("Error in query syntax"), por
isso a constante `CRM_ACTION_ESTORNAR_TRANSACAO` inclui a barra inicial.

Artefatos oficiais do fornecedor recebidos em 24/11/2026 (swagger OpenAPI +
coleção Postman + especificação funcional; mantidos apenas no ambiente local
por conterem credenciais de sandbox): confirmam o **POST** com body JSON, a
versão **v9.2** do Web API (nosso client usa v9.0 via SDK — ambos aceitos
pelo sandbox), o **novo formato alfanumérico de CNPJ** (letras A-Z nas 12
primeiras posições — já aceito por `consultar_saldo()`) e o schema de
resposta idêntico ao shape normalizado desta camada. Divergência de código
de erro: o swagger documenta `0x80048d19` para CNPJ inválido, mas o sandbox
respondeu `0x80040265` — **não ramificar lógica por código de erro**, usar a
mensagem.

## 5. Funções PHP — contratos

### 5.1 Invocação genérica (interna, documentada para reuso interno)

```php
/**
 * Executa uma Custom API do CRM (Action OData, POST JSON).
 *
 * @param string      $action            Nome wire da action (use as constantes ACTION_*).
 * @param array       $parameters        Parâmetros em snake_case (mapeados p/ PascalCase).
 * @param string|null $bound_collection  Entity set da action bound (ex.: 'i4d_transacaos').
 * @param string|null $bound_id          GUID do registro vinculado.
 * @return object|\WP_Error Resposta decodificada e normalizada, ou WP_Error.
 */
function crm_call_custom_action( string $action, array $parameters = [],
    ?string $bound_collection = null, ?string $bound_id = null );
```

Regras:

- Parâmetros são passados com as **chaves wire exatas** da action, montados
  explicitamente pelas funções de domínio — os nomes no CRM são irregulares
  (`cnpj`, `data_associacao`, `contaGuid`, `ParceriaId`), o que inviabiliza
  transformação automática snake→Pascal. Valores `null` são **omitidos** do
  body (o CRM rejeita null onde `Nullable="false"`).
- Sem client (`get_client_on_dynamics()` false) → `WP_Error('crm_no_client', ...)`.
- Falha HTTP/OData → captura `AlexaCRM\WebAPI\OData\ODataException` e extrai
  `error.message`/`error.code` do body via `getInnerException()->getResponse()`
  (detalhe do SDK: a mensagem da exceção carrega só o status code, o body real
  está na exceção Guzzle interna) → devolve
  `WP_Error( "crm_http_{$status}", $mensagem, [ 'action' => $action,
  'crm_error_code' => $error_code, 'status' => $status ] )` — a **mensagem do
  CRM em PT-BR** chega pronta para exibição/log.
- Corpo vazio na resposta → `WP_Error('crm_empty_response', ...)`.
- Nunca lança exceção para o chamador (contrato WP puro: objeto ou `WP_Error`).

### 5.2 Normalização (interna)

```php
function crm_normalize_odata( $value ); // recursivo
```

- Remove toda chave contendo `@odata` (`@odata.context`, `id@odata.type`...);
- Preserva tipos já decodificados (int/float/string); datas permanecem
  strings ISO 8601 — formatação é papel do consumer.

### 5.3 Funções de domínio

Todas: `@return object|\WP_Error`, sanitizam/validam a entrada, sem exceções.

```php
// Consulta o saldo de créditos da associação por CNPJ (14 caracteres
// alfanuméricos — novo padrão de CNPJ aceito —, com ou sem máscara).
// Única função com cache: transient 'saldo_associacao_{hash}' por 60s
// (filtro 'hacklabr/saldo_associacao_cache_ttl'; $fresh ignora).
function consultar_saldo( string $cnpj, bool $fresh = false );

// Registra transação no ledger de créditos (consumo/ajuste).
// $args: parceria_id*, faixa* (∈ A|B|C), tipo_transacao*, quantidade* (int > 0),
//        observacao, data_transacao (ISO), projeto_id, inscricao_id.
function criar_transacao_credito( array $args );

// Lote — $transacoes é array de arrays (mesmas chaves acima, sem parceria_id);
// serializado para JSON internamente.
function criar_transacoes_lote( string $parceria_id, array $transacoes,
    string $observacao = '', string $data_transacao = '' );

// Estorno (estorna crédito; distinto de reembolso de pagamento Getnet) —
// bound à transação informada.
function estornar_transacao( string $transacao_id, string $justificativa );

// Empresas associadas por período (candidata a fonte do sync — [plano] §8.2).
// Datas opcionais em DateTimeInterface, convertidas p/ DateTimeOffset ISO.
function obter_associadas( ?\DateTimeInterface $data_associacao = null,
    ?\DateTimeInterface $termino_associacao = null );

// Contatos aprovados de uma conta (GUID).
function obter_contatos_aprovados( string $conta_guid );
```

**Shape normalizado de `consultar_saldo()`** (contrato estável para os
consumers — o único hoje validado):

```jsonc
{
  "dataConsulta": "2026-11-24T13:26:54Z",
  "parceria": { "id": "<guid>", "nome": "…", "status": "Ativa", "statusId": 1,
                "dataInicio": "…|null", "dataFim": "…|null" },
  "plano":   { "nome": "…", "desconto": 10.0 },
  "resumo":  { "quantidadeTotalCreditos": 12, "totalCreditosPuros": 22 },
  "cliente": { "id": "<guid>", "cnpj": "00.000.000/0000-00", "nome": "…" },
  "saldos":  [ { "faixaId": "<guid>", "faixaNome": "A", "peso": 3,
                 "quantidadeCreditos": 2, "creditosPuros": 6 } ]
}
```

Semântica de `resumo`: `quantidadeTotalCreditos` = soma simples das
quantidades (participações); `totalCreditosPuros` = soma ponderada pelo peso
(base C = 1). Detalhe em [plano] §7.

Shapes das demais actions **observados no sandbox (24/11/2026)**:

- `criar_transacao_credito()` → `{ Sucesso: bool, Mensagem: string,
  TransacaoId: guid, ConversaoPossivel: bool, FaixaDoadora: string|null,
  QuantidadeConversao: int }`. Quando o saldo da faixa é insuficiente, a
  resposta indica a conversão possível (faixa doadora + quantidade) — base
  para a "sugestão de conversão" no momento da inscrição.
- `estornar_transacao()` → `{ TransacaoDevolucaoId: guid, Mensagem: string }`
  — marca `i4d_reembolso_realizado` na transação original e cria a
  devolução correspondente.

- `obter_associadas()` → `{ "value": [ { item… } ] }`; cada item (conta) traz:
  `accountid`, `NomeFantasia`, `CNPJ`, `RazaoSocial`, endereço completo
  (`TipoLogradouro`, `Logradouro`, `Numero`, `Bairro`, `Cidade`, `Estado`,
  `CEP`, `Pais`), `Site`, `entityimage_url`, `Funcionarios`,
  `StateCode`/`StateCodeName`, `Porte`, `TipoAssociacao`, `Associacao`
  (ex.: "Associado"), `DataAssociacao` (dd/mm/AAAA), `CNAE`/`CNAECodigo`,
  `Setor`, `TipoOrganizacao`, `SubtipoOrganizacao`. Sandbox: 456 itens;
  filtros de período funcionam (0 itens para 2026 — datas de associação são
  antigas). **Nota**: não há campos de parceria/vigência — espelha a conta.
- `obter_contatos_aprovados()` → coleção; retornou 0 itens para a conta de
  teste — shape a registrar quando houver conta com aprovados.

### 5.4 Absorção do protótipo (`balance-api.php`) — concluída

- O protótipo **deixa de existir**: a camada absorveu a função de consulta;
  a página admin (*Ethos d365i Saldo*, slug `ethos-saldo-test`) vive em
  **`includes/admin-saldo-test.php`** — separada por responsabilidade
  (apresentação × domínio; ver §6).
- `consultar_saldo_cnpj()` permanece como alias deprecated
  (`@deprecated` → `consultar_saldo()`), pois nunca foi publicado.

## 6. Decisões de design

| Tema | Decisão |
|---|---|
| Nomenclatura | Superfície PHP neutra (domínio); funções de domínio em PT — exceção documentada à convenção "EN para código" (§0); nomes wire só nas constantes `ACTION_*` |
| Contrato de retorno | Objeto normalizado **ou** `WP_Error` — sem exceções para o chamador |
| Códigos de erro | `crm_no_client`, `crm_http_{status}`, `invalid_cnpj`, `invalid_faixa`, `invalid_quantidade` |
| Cache | Apenas leitura de saldo, TTL curto (60s) + bypass `$fresh` (decisão de consumo deve ler saldo fresco) |
| Escrita | `criar_transacao_credito()`, `criar_transacoes_lote()` e `estornar_transacao()` **nunca** cacheadas; log via `do_action('logger', …)` antes/depois |
| Exposição | Nenhuma rota AJAX/REST/shortcode na v1 — uso interno (PHP) e página admin |
| Admin | Página *Ethos d365i Saldo* (Ferramentas) é a única interface de teste (leitura), em arquivo próprio `includes/admin-saldo-test.php` — a camada de domínio fica livre de apresentação. Sem UI de escrita: transações são reais e imutáveis |
| Segurança | `defined('ABSPATH') || exit`; sanitização de entrada nas funções; escapes só na renderização; CNPJ enviado sem máscara (formato alfanumérico aceito) |
| i18n | Text domain `hacklabr` (padrão do plugin) |
| Padrões | WordPress Coding Standards, PT-BR nas mensagens visíveis |

## 7. Consumers mapeados (o porquê do reuso)

| Implementação futura | Usa | Observação |
|---|---|---|
| Inscrição em eventos consome crédito (impacto #5 do [plano]) | `consultar_saldo(fresh)` → `criar_transacao_credito(inscricao_id, projeto_id)` | `InscricaoId`/`ProjetoId` amarram o consumo à inscrição — depende da dúvida 1 (o que é "Inscrição" para o CRM) |
| Área do associado exibe saldo (impacto #8) | `consultar_saldo()` cacheado | Exibição tolera TTL |
| Checagem de vigência no login ([plano] §6.4) | `parceria.dataFim` (saldo) ou meta do sync | Fonte ainda em aberto ([plano] §8.2) |
| Sync incremental parceria/plano ([plano] §10 item 6) | `obter_associadas()` | Candidata a substituir/juntar com a leitura de contas |
| Aprovações de acesso | `obter_contatos_aprovados()` | A validar conjugação com PMPro Approvals |
| Estorno em cancelamento de inscrição | `estornar_transacao()` | Fluxo futuro |

## 8. Fora de escopo (v1)

- `i4d_CarregarSaldoInicial` (operação administrativa, bound a parceria).
- UI de escrita, endpoints REST/AJAX, shortcodes de saldo.
- Leitura direta dos entity sets (`i4d_planoses`, `i4d_faixas`...) — se algum
  consumer precisar (ex.: tabela de benefícios), abre-se extensão à parte.

## 9. Plano de implementação — status

1. ✅ `includes/association-api.php` criado (constantes + genérica +
   normalização + 6 funções de domínio) e exigido no bootstrap.
2. ✅ Protótipo absorvido: `balance-api.php` removido, alias deprecated
   mantido, página admin em arquivo próprio (`includes/admin-saldo-test.php`,
   slug `ethos-saldo-test`).
3. ✅ Testes de leitura no sandbox (24/11/2026): shape sem `@odata`; cache
   (2ª chamada em 0ms); CNPJ inválido → `crm_http_400` com a mensagem do CRM
   (`0x80040265`); CNPJ malformado → `invalid_cnpj`; alias OK; shapes de
   `obter_associadas` (456 itens) e `obter_contatos_aprovados` (vazio)
   registrados em §5.3; `php -l` OK.
4. ✅ **Escrita validada** (24/11/2026, loop com autolimpeza na parceria de
   teste do sandbox): criar (C×1, `consumo_credito`) → saldo 52→51 →
   estornar → saldo 51→52. Formats confirmados: `TipoTransacao` = códigos
   `i4d_id`; `DataTransacao` = AAAA-MM-DD; permissão de escrita do app user
   OK. Bônus: corrigido bug do SDK em actions bound (barra inicial na
   constante). Restam sem teste: `criar_transacoes_lote` e
   `i4d_CarregarSaldoInicial` (fora de escopo).
5. ⬜ Atualizar [plano] §7/§10 com o resultado.

## 10. Pendências que travam partes específicas

| Pendência | Trava | Onde |
|---|---|---|
| ~~Formato de `DataTransacao` e valores de `TipoTransacao`~~ | ✅ Resolvido em teste: AAAA-MM-DD e códigos `i4d_id` (§4) | — |
| ~~Permissão de escrita do app user~~ | ✅ Confirmada em teste (§9.4); **leitura** em `account`/`contact`/`fut_parceria` também verificada empiricamente no sandbox (24/11/2026) | — |
| ~~Resposta real das actions #2, #4 e #6~~ | ✅ Shapes congelados (§5.3); resta `criar_transacoes_lote` (#3) e o shape não vazio de #7 | §9.4 |
| Semântica de "Inscrição" (dúvida 1) | consumer inscrição | [plano] §4 |
| Peso da faixa A × conversão (dúvida 8) | sugestão de conversão no site | [plano] §4/§7 |

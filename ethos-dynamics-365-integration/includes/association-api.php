<?php
/**
 * Plano de Associação - camada de acesso às Custom APIs do CRM.
 *
 * Definição técnica completa: docs/api-plano-associacao.md (neste plugin).
 * Resumo dos contratos:
 *
 *  - crm_call_custom_action(): invocação genérica (Action OData via POST
 *    JSON), com extração da mensagem de erro do CRM. Interna, mas estável.
 *  - Funções de domínio (PT, ver §0 da definição): consultar_saldo(),
 *    criar_transacao_credito(), criar_transacoes_lote(), estornar_transacao(),
 *    obter_associadas(), obter_contatos_aprovados().
 *
 * Os nomes wire das actions ficam nas constantes CRM_ACTION_*.
 *
 * @package EthosDynamics365IntegrationPlugin
 */

namespace hacklabr;

defined( 'ABSPATH' ) || exit;

/**
 * Nomes wire das Custom APIs no Dynamics 365 (esquema do fornecedor).
 *
 * @internal Não referenciar fora da camada: se as actions forem renomeadas, muda-se apenas neste bloco.
 */
const CRM_ACTION_CONSULTAR_SALDO          = 'i4d_consultar_saldo';
const CRM_ACTION_CRIAR_TRANSACAO          = 'i4d_CriarTransacao';
const CRM_ACTION_CRIAR_TRANSACOES_LOTE    = 'i4d_CriarTransacoesLote';
/*
 * Actions bound: o SDK monta a URL como {entitySet}({id}){nome} — sem o
 * separador "/" —, logo o nome precisa vir com a barra inicial para gerar
 * a sintaxe OData correta: {entitySet}({id})/Microsoft.Dynamics.CRM.{nome}.
 */
const CRM_ACTION_ESTORNAR_TRANSACAO       = '/Microsoft.Dynamics.CRM.i4d_ReembolsarTransacao';
const CRM_ACTION_OBTER_ASSOCIADAS         = 'i4d_obter_associadas';
const CRM_ACTION_OBTER_CONTATOS_APROVADOS = 'i4d_contatos_aprovados';

/** Entity set da transação de crédito (binding do estorno). */
const CRM_ENTITY_SET_TRANSACAO = 'i4d_transacaos';

/**
 * Executa uma Custom API do CRM (Action OData, POST com body JSON).
 *
 * Os parâmetros devem usar as chaves wire exatas da action — os nomes no CRM
 * são irregulares (`cnpj`, `data_associacao`, `contaGuid`, `ParceriaId`), por
 * isso o mapeamento é feito explicitamente pelas funções de domínio, sem
 * transformação automática.
 *
 * @param string      $action            Nome wire da action (constantes CRM_ACTION_*).
 * @param array       $parameters        Parâmetros com chaves wire; valores null são omitidos.
 * @param string|null $bound_collection  Entity set, para actions bound (ex.: 'i4d_transacaos').
 * @param string|null $bound_id          GUID do registro vinculado à action bound.
 *
 * @return object|\WP_Error Resposta decodificada e normalizada, ou WP_Error.
 */
function crm_call_custom_action( string $action, array $parameters = [], ?string $bound_collection = null, ?string $bound_id = null ) {
    $client = get_client_on_dynamics();

    if ( false === $client ) {
        return new \WP_Error(
            'crm_no_client',
            __( 'Não foi possível conectar ao Dynamics 365. Verifique as credenciais no painel (Dynamics 365 Integration).', 'hacklabr' )
        );
    }

    $payload = [];
    foreach ( $parameters as $key => $value ) {
        if ( null === $value ) {
            continue; // O CRM rejeita null onde o parâmetro é Nullable="false".
        }
        $payload[ $key ] = $value;
    }

    try {
        $result = $client->getClient()->executeAction( $action, $payload, $bound_collection, $bound_id );
    } catch ( \AlexaCRM\WebAPI\OData\ODataException $e ) {
        $status   = (int) $e->getCode();
        $message  = $e->getMessage();
        $crm_code = null;

        $inner = $e->getInnerException();
        if ( $inner instanceof \GuzzleHttp\Exception\RequestException && $inner->hasResponse() ) {
            $body = json_decode( (string) $inner->getResponse()->getBody(), true );
            if ( ! empty( $body['error']['message'] ) ) {
                $message  = $body['error']['message'];
                $crm_code = $body['error']['code'] ?? null;
            }
        }

        return new \WP_Error(
            "crm_http_{$status}",
            $message,
            [
                'action'        => $action,
                'crm_error_code' => $crm_code,
                'status'        => $status,
            ]
        );
    } catch ( \Throwable $e ) {
        return new \WP_Error( 'crm_request_failed', $e->getMessage(), [ 'action' => $action ] );
    }

    if ( null === $result ) {
        return new \WP_Error(
            'crm_empty_response',
            __( 'O CRM respondeu a requisição sem conteúdo.', 'hacklabr' ),
            [ 'action' => $action ]
        );
    }

    return crm_normalize_odata( $result );
}

/**
 * Remove anotações OData (@odata.context, id@odata.type...) recursivamente.
 *
 * @param mixed $value Resposta decodificada (objeto, array ou escalar).
 * @return mixed Mesma estrutura, sem chaves de anotação.
 */
function crm_normalize_odata( $value ) {
    if ( is_array( $value ) ) {
        $clean = [];
        foreach ( $value as $key => $item ) {
            if ( is_string( $key ) && strpos( $key, '@odata' ) !== false ) {
                continue;
            }
            $clean[ $key ] = crm_normalize_odata( $item );
        }
        return $clean;
    }

    if ( is_object( $value ) ) {
        $clean = new \stdClass();
        foreach ( get_object_vars( $value ) as $key => $item ) {
            if ( strpos( $key, '@odata' ) !== false ) {
                continue;
            }
            $clean->{$key} = crm_normalize_odata( $item );
        }
        return $clean;
    }

    return $value;
}

/**
 * Converte DateTime para o formato DateTimeOffset aceito pelo CRM (UTC).
 *
 * @param \DateTimeInterface $datetime Data a converter.
 * @return string ISO 8601 em UTC (ex.: '2026-11-24T00:00:00Z').
 */
function crm_to_odata_datetime( \DateTimeInterface $datetime ): string {
    if ( $datetime instanceof \DateTimeImmutable ) {
        $utc = $datetime->setTimezone( new \DateTimeZone( 'UTC' ) );
    } else {
        $utc = ( clone $datetime )->setTimezone( new \DateTimeZone( 'UTC' ) );
    }

    return $utc->format( 'Y-m-d\TH:i:s\Z' );
}

/**
 * Consulta o saldo de créditos da associação de uma empresa pelo CNPJ.
 *
 * Aceita CNPJ numérico ou o novo formato alfanumérico (letras A-Z nas 12
 * primeiras posições), com ou sem máscara — o valor enviado à action é o
 * CNPJ sem máscara.
 *
 * Única função da camada com cache: transient de 60s (filtro 'hacklabr/saldo_associacao_cache_ttl').
 * Decisões de consumo de crédito devem usar $fresh = true para ler o saldo atual.
 *
 * @param string $cnpj  CNPJ com ou sem formatação (14 caracteres alfanuméricos).
 * @param bool   $fresh true para ignorar o cache.
 *
 * @return object|\WP_Error Shape normalizado (ver §5.3 da definição) ou WP_Error.
 */
function consultar_saldo( string $cnpj, bool $fresh = false ) {
    /*
     * Aceita CNPJ com ou sem máscara, inclusive o novo formato ALFANUMÉRICO
     * (letras A-Z nas 12 primeiras posições — padrão documentado no swagger
     * da I4D). Remove apenas os separadores (., /, -) e valida 12 caracteres
     * alfanuméricos + 2 dígitos verificadores.
     */
    $limpo = preg_replace( '/[.\/\-]/', '', $cnpj );

    if ( ! preg_match( '/^[A-Za-z0-9]{12}[0-9]{2}$/', $limpo ) ) {
        return new \WP_Error(
            'invalid_cnpj',
            __( 'CNPJ inválido: informe 14 caracteres alfanuméricos (formato novo aceito), com ou sem máscara.', 'hacklabr' )
        );
    }

    $cache_key = 'saldo_associacao_' . sha1( $limpo );

    if ( ! $fresh ) {
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }
    }

    $result = crm_call_custom_action( CRM_ACTION_CONSULTAR_SALDO, [
        'cnpj' => $limpo,
    ] );

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    $ttl = (int) apply_filters( 'hacklabr/saldo_associacao_cache_ttl', MINUTE_IN_SECONDS );
    set_transient( $cache_key, $result, $ttl );

    return $result;
}

/**
 * Registra uma transação no ledger de créditos da associação (consumo/ajuste).
 *
 * ATENÇÃO: transações são reais e imutáveis no CRM (o estorno cria uma
 * devolução vinculada, não apaga o registro). Formatos validados em teste
 * (24/11/2026): tipo_transacao aceita os códigos i4d_id da tabela
 * i4d_tipo_transacaos (ex.: 'consumo_credito'); data_transacao deve ser
 * AAAA-MM-DD (a action recusa timestamp completo).
 *
 * @param array $args {
 *     @type string $parceria_id     (obrigatório) GUID da parceria.
 *     @type string $faixa           (obrigatório) 'A', 'B' ou 'C'.
 *     @type string $tipo_transacao  (obrigatório) Código i4d_id do tipo.
 *     @type int    $quantidade      (obrigatório) Quantidade de créditos (> 0).
 *     @type string $observacao      Observação livre.
 *     @type string $data_transacao  Data da transação (AAAA-MM-DD).
 *     @type string $projeto_id      GUID do projeto (opcional).
 *     @type string $inscricao_id    GUID da inscrição (opcional).
 * }
 *
 * @return object|\WP_Error Resposta da action ou WP_Error.
 */
function criar_transacao_credito( array $args ) {
    $defaults = [
        'parceria_id'    => '',
        'faixa'          => '',
        'tipo_transacao' => '',
        'quantidade'     => 0,
        'observacao'     => '',
        'data_transacao' => '',
        'projeto_id'     => null,
        'inscricao_id'   => null,
    ];
    $args = wp_parse_args( $args, $defaults );

    if ( empty( $args['parceria_id'] ) ) {
        return new \WP_Error( 'invalid_parceria_id', __( 'ID da parceria é obrigatório.', 'hacklabr' ) );
    }

    $faixa = strtoupper( trim( $args['faixa'] ) );
    if ( ! in_array( $faixa, [ 'A', 'B', 'C' ], true ) ) {
        return new \WP_Error( 'invalid_faixa', __( 'Faixa deve ser A, B ou C.', 'hacklabr' ) );
    }

    if ( empty( $args['tipo_transacao'] ) ) {
        return new \WP_Error( 'invalid_tipo_transacao', __( 'Tipo da transação é obrigatório.', 'hacklabr' ) );
    }

    $quantidade = absint( $args['quantidade'] );
    if ( $quantidade < 1 ) {
        return new \WP_Error( 'invalid_quantidade', __( 'Quantidade deve ser um inteiro maior que zero.', 'hacklabr' ) );
    }

    do_action( 'logger', sprintf(
        '[associação] criar_transacao_credito: parceria=%s faixa=%s tipo=%s qtd=%d',
        $args['parceria_id'],
        $faixa,
        $args['tipo_transacao'],
        $quantidade
    ) );

    $result = crm_call_custom_action( CRM_ACTION_CRIAR_TRANSACAO, [
        'ParceriaId'    => $args['parceria_id'],
        'Faixa'         => $faixa,
        'TipoTransacao' => $args['tipo_transacao'],
        'Quantidade'    => $quantidade,
        'Observacao'    => $args['observacao'],
        'DataTransacao' => $args['data_transacao'],
        'ProjetoId'     => $args['projeto_id'],
        'InscricaoId'   => $args['inscricao_id'],
    ] );

    if ( is_wp_error( $result ) ) {
        do_action( 'logger', '[associação] criar_transacao_credito FALHOU: ' . $result->get_error_message() );
    }

    return $result;
}

/**
 * Registra várias transações de crédito em lote numa parceria.
 *
 * @param string $parceria_id   GUID da parceria.
 * @param array  $transacoes    Lista de arrays com as mesmas chaves de
 *                              criar_transacao_credito() (sem parceria_id).
 * @param string $observacao    Observação única do lote.
 * @param string $data_transacao Data das transações (formato a confirmar).
 *
 * @return object|\WP_Error Resposta da action ou WP_Error.
 */
function criar_transacoes_lote( string $parceria_id, array $transacoes, string $observacao = '', string $data_transacao = '' ) {
    if ( empty( $parceria_id ) ) {
        return new \WP_Error( 'invalid_parceria_id', __( 'ID da parceria é obrigatório.', 'hacklabr' ) );
    }

    if ( empty( $transacoes ) ) {
        return new \WP_Error( 'invalid_transacoes', __( 'O lote deve conter ao menos uma transação.', 'hacklabr' ) );
    }

    do_action( 'logger', sprintf( '[associação] criar_transacoes_lote: parceria=%s itens=%d', $parceria_id, count( $transacoes ) ) );

    return crm_call_custom_action( CRM_ACTION_CRIAR_TRANSACOES_LOTE, [
        'ParceriaId'    => $parceria_id,
        'Transacoes'    => wp_json_encode( $transacoes ),
        'Observacao'    => $observacao,
        'DataTransacao' => $data_transacao,
    ] );
}

/**
 * Estorna (devolve créditos de) uma transação do ledger.
 *
 * Distinto de reembolso de pagamento (Getnet): estorno é a devolução de
 * créditos da associação ao saldo.
 *
 * @param string $transacao_id GUID da transação a estornar.
 * @param string $justificativa Motivo do estorno.
 *
 * @return object|\WP_Error Resposta da action ou WP_Error.
 */
function estornar_transacao( string $transacao_id, string $justificativa ) {
    if ( empty( $transacao_id ) ) {
        return new \WP_Error( 'invalid_transacao_id', __( 'ID da transação é obrigatório.', 'hacklabr' ) );
    }

    if ( '' === trim( $justificativa ) ) {
        return new \WP_Error( 'invalid_justificativa', __( 'Justificativa é obrigatória para o estorno.', 'hacklabr' ) );
    }

    do_action( 'logger', sprintf( '[associação] estornar_transacao: transacao=%s', $transacao_id ) );

    return crm_call_custom_action(
        CRM_ACTION_ESTORNAR_TRANSACAO,
        [ 'Justificativa' => $justificativa ],
        CRM_ENTITY_SET_TRANSACAO,
        $transacao_id
    );
}

/**
 * Lista empresas associadas, opcionalmente por período de associação.
 *
 * Candidata a fonte da sincronização incremental (ver definição §7 e novo-plano-associacao.md §8.2).
 *
 * @param \DateTimeInterface|null $data_associacao   Início do período.
 * @param \DateTimeInterface|null $termino_associacao Fim do período.
 *
 * @return object|\WP_Error Coleção normalizada ou WP_Error.
 */
function obter_associadas( ?\DateTimeInterface $data_associacao = null, ?\DateTimeInterface $termino_associacao = null ) {
    return crm_call_custom_action( CRM_ACTION_OBTER_ASSOCIADAS, [
        'data_associacao'    => ( null === $data_associacao ) ? null : crm_to_odata_datetime( $data_associacao ),
        'termino_associacao' => ( null === $termino_associacao ) ? null : crm_to_odata_datetime( $termino_associacao ),
    ] );
}

/**
 * Lista os contatos aprovados de uma conta (GUID).
 *
 * @param string $conta_guid GUID da conta no CRM.
 *
 * @return object|\WP_Error Coleção normalizada ou WP_Error.
 */
function obter_contatos_aprovados( string $conta_guid ) {
    if ( empty( $conta_guid ) ) {
        return new \WP_Error( 'invalid_conta_guid', __( 'GUID da conta é obrigatório.', 'hacklabr' ) );
    }

    return crm_call_custom_action( CRM_ACTION_OBTER_CONTATOS_APROVADOS, [
        'contaGuid' => $conta_guid,
    ] );
}

/**
 * Alias mantido para compatibilidade com o protótipo (balance-api.php,
 * nunca publicado). Prefira consultar_saldo().
 *
 * @deprecated Use hacklabr\consultar_saldo().
 *
 * @param string $cnpj CNPJ com ou sem formatação (14 caracteres alfanuméricos).
 * @return object|\WP_Error
 */
function consultar_saldo_cnpj( string $cnpj ) {
    return consultar_saldo( $cnpj );
}

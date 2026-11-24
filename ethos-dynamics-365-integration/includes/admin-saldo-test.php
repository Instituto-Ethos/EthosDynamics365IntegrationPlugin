<?php
/**
 * Plano de Associação — página de teste da consulta de saldo (admin).
 *
 * Ferramenta de validação da Custom API de saldo (camada:
 * `includes/association-api.php`). Registre-a em Ferramentas → Ethos d365i
 * Saldo. Somente leitura: consulta o saldo pelo CNPJ com bypass de cache.
 *
 * @package EthosDynamics365IntegrationPlugin
 */

namespace hacklabr;

defined( 'ABSPATH' ) || exit;

/**
 * Registra a página de teste da consulta de saldo em Ferramentas.
 */
function add_saldo_test_page() {
    add_management_page(
        __( 'Consulta de saldo (Plano de Associação)', 'hacklabr' ),
        __( 'Ethos d365i Saldo', 'hacklabr' ),
        'manage_options',
        'ethos-saldo-test',
        'hacklabr\render_saldo_test_page'
    );
}
add_action( 'admin_menu', 'hacklabr\add_saldo_test_page' );

/**
 * Renderiza a página de teste: formulário com CNPJ e exibição da resposta.
 */
function render_saldo_test_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Sem permissão para acessar esta página.', 'hacklabr' ) );
    }

    $result = null;

    if ( ! empty( $_POST['ethos_saldo_cnpj'] ) ) {
        if ( ! isset( $_POST['ethos_saldo_test_nonce'] ) ||
             ! wp_verify_nonce( sanitize_key( $_POST['ethos_saldo_test_nonce'] ), 'ethos_saldo_test' ) ) {
            wp_die( __( 'Falha na verificação de segurança (nonce). Tente novamente.', 'hacklabr' ) );
        }

        $result = consultar_saldo( sanitize_text_field( wp_unslash( $_POST['ethos_saldo_cnpj'] ) ), true );
    }

    $server_url = get_crm_server_url();

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Consulta de Saldo — Plano de Associação', 'hacklabr' ) . '</h1>';
    echo '<p>' . esc_html__( 'Consulta a Custom API de saldo do Plano de Associação no CRM.', 'hacklabr' ) . '</p>';
    echo '<p><strong>' . esc_html__( 'Ambiente (credenciais do painel):', 'hacklabr' ) . '</strong> <code>' . esc_html( $server_url ?: __( 'não configurado', 'hacklabr' ) ) . '</code></p>';

    echo '<form method="post">';
    wp_nonce_field( 'ethos_saldo_test', 'ethos_saldo_test_nonce' );
    echo '<p>';
    echo '<label for="ethos_saldo_cnpj"><strong>' . esc_html__( 'CNPJ da empresa associada:', 'hacklabr' ) . '</strong></label><br />';
    echo '<input type="text" class="regular-text" name="ethos_saldo_cnpj" id="ethos_saldo_cnpj" placeholder="00.000.000/0000-00" value="" required />';
    echo '</p>';
    echo '<p>';
    submit_button( __( 'Consultar saldo', 'hacklabr' ), 'primary', 'ethos_saldo_submit', false );
    echo '</p>';
    echo '</form>';

    if ( null !== $result ) {
        echo '<hr />';
        echo '<h2>' . esc_html__( 'Resultado', 'hacklabr' ) . '</h2>';

        if ( is_wp_error( $result ) ) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html( $result->get_error_code() ) . ':</strong> ' . esc_html( $result->get_error_message() ) . '</p></div>';
        } else {
            echo '<pre style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:12px;overflow:auto;max-height:600px;">'
                . esc_html( json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) )
                . '</pre>';
        }
    }

    echo '</div>';
}

<?php

namespace hacklabr;

function add_sync_status_page() {
    add_management_page(
        __( 'Sincronização de cadastros', 'hacklabr' ),
        __( 'Ethos d365i Sync', 'hacklabr' ),
        'manage_options',
        'sync-posts',
        'hacklabr\\render_sync_status_page'
    );
}

add_action( 'admin_menu', 'hacklabr\\add_sync_status_page' );

function render_sync_status_page() {
    $waiting_sync = get_option( '_ethos_sync_waiting_list', [] );
    $waiting_approval = get_option( '_ethos_waiting_approval', [] );

    if ( isset( $_GET['action'] ) && isset( $_GET['post_id'] ) ) {
        if ( $_GET['action'] === 'cancel_sync' ) {
            $post_id = intval( $_GET['post_id'] );
            cancel_sync( $post_id );
        }
    }

    echo '<div class="wrap">';

    echo '<h1>' . __( 'Lista de cadastros aguardando sincronização.', 'hacklabr' ) . '</h1>';

    if ( $waiting_sync ) {

        $get_waiting_sync_posts = get_posts( [
            'post_type'      => 'organizacao',
            'post__in'       => $waiting_sync,
            'posts_per_page' => -1
        ] );

        echo '<h2>' . __( 'Cadastros aguardando envio para o CRM.', 'hacklabr' ) . '</h2>';

        if ( $get_waiting_sync_posts ) {
            echo '<table class="widefat">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>' . __( 'Título', 'hacklabr' ) . '</th>';
            echo '<th>' . __( 'Status', 'hacklabr' ) . '</th>';
            echo '<th>' . __( 'Ações', 'hacklabr' ) . '</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ( $get_waiting_sync_posts as $p ) {
                $get_log_error = get_post_meta( $p->ID, 'log_error', true );

                if ( ! $get_log_error ) {
                    $get_log_error = __( 'Aguardando envio para o CRM', 'hacklabr' );
                }

                echo '<tr>';
                echo '<td>' . $p->post_title . '</td>';
                echo '<td>' . $get_log_error . '</td>';
                echo '<td>';
                echo '<a href="' . get_edit_post_link( $p->ID ) . '" class="button button-primary">' . __( 'Ver organização', 'hacklabr' ) . '</a>';
                echo ' ';
                echo '<a href="' . admin_url( 'admin.php?page=sync-posts&action=cancel_sync&post_id=' . $p->ID ) . '" class="button">' . __( 'Cancelar', 'hacklabr' ) . '</a>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
            echo '<br/>';
        }

    }

    if ( $waiting_approval ) {
        $get_waitting_approval_posts = get_posts( [
            'post_type'      => 'organizacao',
            'post__in'       => $waiting_approval,
            'posts_per_page' => -1
        ] );

        echo '<h2>' . __( 'Cadastros aguardando aprovação do lead.', 'hacklab' ) . '</h2>';

        if ( $get_waitting_approval_posts ) {
            echo '<table class="widefat">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>' . __( 'Título', 'hacklabr' ) . '</th>';
            echo '<th>' . __( 'Status', 'hacklabr' ) . '</th>';
            echo '<th>' . __( 'Ações', 'hacklabr' ) . '</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ( $get_waitting_approval_posts as $p ) {
                echo '<tr>';
                echo '<td>' . $p->post_title . '</td>';
                echo '<td>' . __( 'Aguardando aprovação do lead no CRM', 'hacklabr' ) . '</td>';
                echo '<td>';
                echo '<a href="' . get_edit_post_link( $p->ID ) . '" class="button button-primary">' . __( 'Ver organização', 'hacklabr' ) . '</a>';
                echo ' ';
                echo '<a href="' . admin_url( 'admin.php?page=sync-posts&action=cancel_sync&post_id=' . $p->ID ) . '" class="button">' . __( 'Cancelar', 'hacklabr' ) . '</a>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
            echo '<br/>';
        }
    }

    echo '</div>';
}

function add_settings_page() {
    add_options_page(
        'Configurações de Sync',
        'Configurações de Sync',
        'manage_options',
        'sync-settings',
        'hacklabr\\sync_settings_render'
    );
}
add_action( 'admin_menu', 'hacklabr\\add_settings_page' );

function sync_settings_render() {
    ?>
    <div class="wrap">
        <h1>Configurações de Sync</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'sync_settings_group' );
            do_settings_sections( 'sync-settings' );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

function settings_init() {
    register_setting( 'sync_settings_group', 'systemuser' );

    add_settings_section(
        'sync_settings_section',
        'Integração com o Dynamics 365',
        null,
        'sync-settings'
    );

    add_settings_field(
        'systemuser',
        'Usuário do sistema',
        'hacklabr\\sync_user_field_callback',
        'sync-settings',
        'sync_settings_section'
    );
}
add_action( 'admin_init', 'hacklabr\\settings_init' );

function sync_user_field_callback() {
    $systemuser = get_option( 'systemuser' );
    echo '<input type="text" name="systemuser" value="' . esc_attr( $systemuser ) . '" />';
}


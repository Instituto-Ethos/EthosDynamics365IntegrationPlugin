<?php

namespace hacklabr;

defined( 'ABSPATH' ) || exit;

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
    $waiting_sync = get_sync_waiting_list();
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

        <hr />

        <h2>Ações manuais</h2>
        <p>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=run_reconciliation' ), 'run_reconciliation' ) ); ?>" class="button button-secondary">
                Executar reconciliação
            </a>
            <span class="description">Move para a lixeira organizações ativas no WP que não existem mais no CRM.</span>
        </p>
        <p>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=run_deduplication' ), 'run_deduplication' ) ); ?>" class="button button-secondary">
                Deduplicar organizações
            </a>
            <span class="description">Remove duplicatas mantendo apenas o post mais recente por Account ID.</span>
        </p>
        <p>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fix_orphaned_events' ), 'fix_orphaned_events' ) ); ?>" class="button button-secondary">
                Corrigir eventos orfãos
            </a>
            <span class="description">Recria registros na custom table do TEC para eventos sem entrada em wp_tec_events (batch de 10 por tick de 5min).</span>
        </p>

        <?php
        if ( isset( $_GET['fix_orphaned_events'] ) && intval( $_GET['fix_orphaned_events'] ) === 1 ) {
            echo '<div class="notice notice-success is-dismissible"><p>Job de correcao de eventos orfaos enfileirado. Acompanhe o progresso em Ferramentas > WP Logger.</p></div>';
        }
        ?>

        <?php
        $last_recon = get_option( '_ethos_last_reconciliation' );
        if ( ! empty( $last_recon ) ) :
        ?>
        <h3>Última reconciliação</h3>
        <table class="widefat striped">
            <tr><th>Data/hora</th><td><?php echo esc_html( $last_recon['datetime'] ?? '' ); ?></td></tr>
            <tr><th>Organizações no WP</th><td><?php echo (int) ( $last_recon['total_wp'] ?? 0 ); ?></td></tr>
            <tr><th>Organizações no CRM</th><td><?php echo (int) ( $last_recon['total_crm'] ?? 0 ); ?></td></tr>
            <tr><th>Movidas para lixeira</th><td><?php echo (int) ( $last_recon['trashed'] ?? 0 ); ?></td></tr>
        </table>
        <?php if ( ! empty( $last_recon['orphans'] ) ) : ?>
        <h4>Organizações removidas</h4>
        <table class="widefat">
            <thead><tr><th>Post ID</th><th>Nome</th><th>Account ID</th></tr></thead>
            <tbody>
            <?php foreach ( $last_recon['orphans'] as $orphan ) : ?>
                <tr>
                    <td><?php echo (int) $orphan['post_id']; ?></td>
                    <td><?php echo esc_html( $orphan['post_title'] ); ?></td>
                    <td><code><?php echo esc_html( $orphan['account_id'] ); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php endif; ?>

        <?php
        $last_dedup = get_option( '_ethos_last_deduplication' );
        if ( ! empty( $last_dedup ) ) :
        ?>
        <h3>Última deduplicação</h3>
        <table class="widefat striped">
            <tr><th>Data/hora</th><td><?php echo esc_html( $last_dedup['datetime'] ?? '' ); ?></td></tr>
            <tr><th>Grupos duplicados</th><td><?php echo (int) ( $last_dedup['duplicates'] ?? 0 ); ?></td></tr>
            <tr><th>Posts removidos</th><td><?php echo (int) ( $last_dedup['trashed'] ?? 0 ); ?></td></tr>
        </table>
        <?php if ( ! empty( $last_dedup['groups'] ) ) : ?>
        <h4>Detalhes por grupo</h4>
        <table class="widefat">
            <thead><tr><th>Account ID</th><th>Post mantido</th><th>Posts removidos</th></tr></thead>
            <tbody>
            <?php foreach ( $last_dedup['groups'] as $group ) : ?>
                <tr>
                    <td><code><?php echo esc_html( $group['account_id'] ); ?></code></td>
                    <td><?php echo (int) $group['kept']; ?></td>
                    <td><?php echo esc_html( implode( ', ', $group['trashed_ids'] ?? [] ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php endif; ?>

        <?php
        $orphaned_events = \hacklabr\get_orphaned_events_list();
        if ( ! empty( $orphaned_events ) ) :
        ?>
        <hr />
        <h3>Eventos com problema (404 na single)</h3>
        <p class="description">
            <?php printf( esc_html__( '%d evento(s) publicado(s) sem registro na custom table do TEC. Essas singles retornam 404.', 'hacklabr' ), count( $orphaned_events ) ); ?>
        </p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Título</th>
                    <th>Data do evento</th>
                    <th>Entity ID</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $orphaned_events as $event ) :
                $edit_url  = get_edit_post_link( $event->ID );
                $view_url  = get_permalink( $event->ID );
                $entity_id = get_post_meta( $event->ID, 'entity_fut_projeto', true );
                $start     = get_post_meta( $event->ID, '_EventStartDate', true );
            ?>
                <tr>
                    <td><?php echo (int) $event->ID; ?></td>
                    <td><?php echo esc_html( $event->post_title ); ?></td>
                    <td><?php echo esc_html( $start ?: '—' ); ?></td>
                    <td><code><?php echo esc_html( $entity_id ?: '—' ); ?></code></td>
                    <td>
                        <?php if ( $edit_url ) : ?>
                            <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">Editar</a>
                        <?php endif; ?>
                        <a href="<?php echo esc_url( $view_url ); ?>" class="button button-small" target="_blank">Ver single</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php elseif ( isset( $_GET['page'] ) && $_GET['page'] === 'sync-settings' ) : ?>
        <hr />
        <h3>Eventos com problema (404 na single)</h3>
        <p class="description">Nenhum evento órfão encontrado. Todos os eventos possuem registro na custom table do TEC.</p>
        <?php endif; ?>

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

function handle_run_reconciliation() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }

    check_admin_referer( 'run_reconciliation' );

    \ethos\crm\run_reconciliation();

    wp_safe_redirect( admin_url( 'options-general.php?page=sync-settings' ) );
    exit;
}
add_action( 'admin_post_run_reconciliation', 'hacklabr\\handle_run_reconciliation' );

function handle_run_deduplication() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }

    check_admin_referer( 'run_deduplication' );

    \ethos\crm\run_deduplication();

    wp_safe_redirect( admin_url( 'options-general.php?page=sync-settings' ) );
    exit;
}
add_action( 'admin_post_run_deduplication', 'hacklabr\\handle_run_deduplication' );

function handle_fix_orphaned_events() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }

    check_admin_referer( 'fix_orphaned_events' );

    \ethos\crm\ensure_jobs_table();
    \ethos\crm\schedule_job( 'fix_orphaned_events', '' );
    do_action( 'logger', 'fix_orphaned_events: Job enfileirado via admin - iniciando correcao.', 'info' );

    wp_safe_redirect( admin_url( 'options-general.php?page=sync-settings&fix_orphaned_events=1' ) );
    exit;
}
add_action( 'admin_post_fix_orphaned_events', 'hacklabr\\handle_fix_orphaned_events' );


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
                echo '<td>' . esc_html( $p->post_title ) . '</td>';
                echo '<td>' . esc_html( $get_log_error ) . '</td>';
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

        echo '<h2>' . __( 'Cadastros aguardando aprovação do lead.', 'hacklabr' ) . '</h2>';

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

        <?php render_migration_status_section(); ?>

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

        <?php render_migration_logs_panel(); ?>

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

/**
 * Renders the "Migração incremental" section on the Configurações de Sync
 * admin page (called directly from sync_settings_render()).
 *
 * Render-only (no actions): scheduled run status, last run stats and
 * access to the daily migration log files. Data and helpers come from
 * the Ethos Migration Plugin; nothing renders if it is inactive.
 */
function render_migration_status_section() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( ! defined( 'ethos\\migration\\LOG_DIR' ) ) {
        return;
    }

    $next_run = wp_next_scheduled( 'ethos_migration\run_daily' );
    $is_running = ! empty( get_transient( \ethos\migration\LOCK_KEY ) );
    $last_run = get_option( \ethos\migration\LAST_RUN_OPTION, [] );

    echo '<hr />';
    echo '<h2>Migração incremental (diária)</h2>';

    echo '<table class="widefat striped" style="max-width:600px;">';
    if ( $is_running ) {
        echo '<tr><th>Status</th><td><strong>Execução em andamento</strong></td></tr>';
    }
    if ( ! empty( $next_run ) ) {
        echo '<tr><th>Próxima execução</th><td>' . esc_html( wp_date( 'd/m/Y H:i:s P', $next_run ) ) . '</td></tr>';
    } else {
        echo '<tr><th>Próxima execução</th><td><strong>Evento não agendado</strong> (será reagendado no próximo acesso ao site)</td></tr>';
    }
    echo '</table>';

    if ( ! empty( $last_run ) ) {
        echo '<h3>Última execução</h3>';
        echo '<table class="widefat striped" style="max-width:600px;">';
        echo '<tr><th>Início</th><td>' . esc_html( $last_run['started'] ?? '' ) . '</td></tr>';
        echo '<tr><th>Fim</th><td>' . esc_html( $last_run['finished'] ?? '' ) . '</td></tr>';
        echo '<tr><th>Duração</th><td>' . esc_html( ( $last_run['duration_s'] ?? 0 ) . ' s' ) . '</td></tr>';
        echo '<tr><th>Contas ativas no CRM</th><td>' . (int) ( $last_run['active_accounts'] ?? 0 ) . '</td></tr>';
        echo '<tr><th>Contatos importados/atualizados</th><td>' . (int) ( $last_run['contacts'] ?? 0 ) . '</td></tr>';
        echo '<tr><th>Erros</th><td>' . (int) ( $last_run['errors'] ?? 0 ) . '</td></tr>';

        $cleanup = $last_run['cleanup'] ?? [];
        if ( ( $cleanup['status'] ?? '' ) === 'done' ) {
            $cleanup_text = 'Concluída — ' . (int) ( $cleanup['removed'] ?? 0 ) . ' organização(ões) removida(s), ' . (int) ( $cleanup['errors'] ?? 0 ) . ' erro(s)';
        } elseif ( ( $cleanup['status'] ?? '' ) === 'skipped' ) {
            $cleanup_text = 'Ignorada — ' . esc_html( $cleanup['reason'] ?? 'motivo desconhecido' );
        } else {
            $cleanup_text = '—';
        }
        echo '<tr><th>Limpeza de inativos</th><td>' . $cleanup_text . '</td></tr>';

        echo '</table>';
    }
}

/**
 * Lists available migration log files, newest first.
 *
 * @return string[] Absolute file paths.
 */
function get_migration_log_files() : array {
    $files = glob( \ethos\migration\LOG_DIR . '/migration-*.log' );

    if ( empty( $files ) ) {
        return [];
    }

    rsort( $files ); // newest first (YYYY-MM-DD in filename)

    return $files;
}

/**
 * Validates a requested log filename and returns its resolved path,
 * or null when invalid (strict pattern + realpath containment).
 *
 * @return string|null
 */
function validate_migration_log_file( string $filename ) : ?string {
    if ( ! preg_match( '/^migration-\d{4}-\d{2}-\d{2}\.log$/', $filename ) ) {
        return null;
    }

    $realpath = realpath( \ethos\migration\LOG_DIR . '/' . $filename );

    if ( false === $realpath || ! is_file( $realpath ) || ! str_starts_with( $realpath, (string) realpath( \ethos\migration\LOG_DIR ) ) ) {
        return null;
    }

    return $realpath;
}

/**
 * Renders the migration logs panel: file list plus the selected file's
 * full contents in a scrollable pre.
 */
function render_migration_logs_panel() {
    if ( ! current_user_can( 'manage_options' ) || ! defined( 'ethos\\migration\\LOG_DIR' ) ) {
        return;
    }

    echo '<hr />';
    echo '<h3>Logs de migração</h3>';

    $files = get_migration_log_files();

    if ( empty( $files ) ) {
        echo '<p class="description">Nenhum arquivo de log encontrado (a primeira execução cria os logs).</p>';
        return;
    }

    $requested = isset( $_GET['migration_log'] ) ? sanitize_text_field( wp_unslash( $_GET['migration_log'] ) ) : '';
    $selected = ! empty( $requested ) ? validate_migration_log_file( $requested ) : null;

    if ( null === $selected ) {
        $selected = $files[0];
    }

    echo '<p>';
    foreach ( $files as $file ) {
        $basename = basename( $file );

        if ( $file === $selected ) {
            echo '<strong>[' . esc_html( $basename ) . ']</strong> ';
        } else {
            $url = add_query_arg( [ 'page' => 'sync-settings', 'migration_log' => $basename ], admin_url( 'options-general.php' ) );
            echo '<a href="' . esc_url( $url ) . '">' . esc_html( $basename ) . '</a> ';
        }

        echo '<span class="description">(' . esc_html( size_format( (int) filesize( $file ) ) ) . ')</span> ';
    }
    echo '</p>';

    $contents = file_get_contents( $selected );

    if ( false === $contents || '' === $contents ) {
        echo '<p class="description">Arquivo vazio.</p>';
        return;
    }

    echo '<pre style="max-height:600px; overflow:auto; background:#fff; border:1px solid #c3c4c7; padding:12px;">' . esc_html( $contents ) . '</pre>';
}


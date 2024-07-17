<?php

namespace hacklabr;

function schedule_sync_entity() {
    $waiting_list = get_option( '_ethos_sync_waiting_list', [] );

    foreach ( $waiting_list as $post_id ) {
        if ( ! wp_next_scheduled( 'sync_entity', [$post_id] ) ) {
            wp_schedule_single_event( time() + 30, 'sync_entity', [$post_id] );
        }
    }
}

add_action( 'init', 'hacklabr\schedule_sync_entity' );

function schedule_approval_entity( $post_id ) {
    $waiting_approval = get_option( '_ethos_waiting_approval', [] );

    foreach ( $waiting_approval as $post_id ) {
        if ( ! wp_next_scheduled( 'approval_entity', [$post_id] ) ) {
            wp_schedule_single_event( time() + 30, 'approval_entity', [$post_id] );
        }
    }
}

add_action( 'init', 'hacklabr\schedule_approval_entity' );

add_action( 'hacklabr\\run_every_hour', 'hacklabr\\do_get_crm_events' );

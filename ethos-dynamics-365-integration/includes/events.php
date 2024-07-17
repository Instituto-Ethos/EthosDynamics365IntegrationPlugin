<?php

namespace hacklabr;

function event_exists_on_wp( $entity_id ) {
    $args = [
        'post_type'      => 'tribe_events',
        'meta_query'     => [
            [
                'key'     => 'entity_fut_projeto',
                'value'   => $entity_id,
                'compare' => '='
            ]
        ],
        'fields'         => 'ids',
        'posts_per_page' => 1
    ];

    $get_events = get_posts( $args );

    if ( count( $get_events ) > 0 ) {
        return $get_events[0];
    }

    return false;
}

function create_event_on_wp( $entity ) {

    if ( ! \class_exists( 'Tribe__Events__API' ) ) {
        return false;
    }

    if ( ! isset( $entity->Attributes['fut_dt_realizacao'] ) ) {
        do_action( 'logger', "Não existe a data de realização do evento. Entity ID: $entity->Id" );
        return false;
    }

    if ( ! isset( $entity->Attributes['fut_dt_dataehoratermino'] ) ) {
        do_action( 'logger', "Não existe a data de término do evento. Entity ID: $entity->Id" );
        return false;
    }

    $start_date = format_iso8601_to_events( $entity->Attributes['fut_dt_realizacao'] );
    $end_date   = format_iso8601_to_events( $entity->Attributes['fut_dt_dataehoratermino'] );
    $title      = $entity->Attributes['fut_name'] ?? '';
    $url        = $entity->Attributes['fut_st_website'] ?? '';

    $args = [
        'EventEndDate'       => $end_date['EventDate'],
        'EventEndHour'       => $end_date['EventHour'],
        'EventEndMeridian'   => $end_date['EventMeridian'],
        'EventEndMinute'     => $end_date['EventMinute'],
        'EventStartDate'     => $start_date['EventDate'],
        'EventStartHour'     => $start_date['EventHour'],
        'EventStartMeridian' => $start_date['EventMeridian'],
        'EventStartMinute'   => $start_date['EventMinute'],
        'EventURL'           => $url,
        'post_status'        => 'publish',
        'post_title'         => $title
    ];

    $args = array_filter( $args );
    $result = \Tribe__Events__API::createEvent( $args );

    if ( $result && ! is_wp_error( $result ) ) {
        update_post_meta( $result, 'entity_fut_projeto', $entity->Id );
    }

    return $result;

}

function get_crm_projects_by_type( $name, $args = [] ) {
    $get_all_tipodeprojeto = get_crm_entities( 'fut_tipodeprojeto', ['count' => 99] );
    $result = false;

    if ( $get_all_tipodeprojeto ) {
        foreach ( $get_all_tipodeprojeto as $tipoparceria ) {
            if ( $tipoparceria->Attributes['fut_name'] == $name ) {
                $result = get_crm_entities_by_attribute( 'fut_projeto', 'fut_lk_tipoparceria', $tipoparceria->Id, $args );
                $result = $result->Entities;
            }
        }
    }

    return $result;
}

function do_get_crm_events() {
    $events = get_crm_projects_by_type( 'Evento', ['count' => 5] );

    if ( $events ) {
        foreach( $events as $event ) {
            if ( ! event_exists_on_wp( $event->Id ) ) {
                create_event_on_wp( $event );
            }
        }
    }
}

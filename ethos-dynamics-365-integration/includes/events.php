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

    if(intval($entity->Attributes['statuscode'] ?? 0) == 2) {
        do_action( 'logger', "O evento está inativo. Entity ID: $entity->Id" );
        return false;
    }

    $start_date = format_iso8601_to_events( $entity->Attributes['fut_dt_realizacao'] );
    $end_date   = format_iso8601_to_events( $entity->Attributes['fut_dt_dataehoratermino'] );
    $title      = $entity->Attributes['fut_name'] ?? '';
    $url        = $entity->Attributes['fut_st_website'] ?? '';
    $cost       = $entity->Attributes['fut_valorinscricao'] ?? '';

    $args = [
        'EventCost'               => format_currency_value( $cost ),
        'EventCurrencyCode'       => 'BRL',
        'EventCurrencyPosition'   => 'prefix',
        'EventCurrencySymbol'     => 'R$',
        'EventDateTimeSeparator'  => ' @ ',
        'EventEndDate'            => $end_date['EventDate'],
        'EventEndHour'            => $end_date['EventHour'],
        'EventEndMeridian'        => $end_date['EventMeridian'],
        'EventEndMinute'          => $end_date['EventMinute'],
        'EventStartDate'          => $start_date['EventDate'],
        'EventStartHour'          => $start_date['EventHour'],
        'EventStartMeridian'      => $start_date['EventMeridian'],
        'EventStartMinute'        => $start_date['EventMinute'],
        'EventTimeRangeSeparator' => ' - ',
        'EventTimezone'           => 'UTC-3',
        'EventURL'                => $url,
        'post_status'             => 'publish',
        'post_title'              => $title
    ];

    $args = array_filter( $args );
    $result = \Tribe__Events__API::createEvent( $args );

    if ( $result && ! is_wp_error( $result ) ) {
        update_post_meta( $result, 'entity_fut_projeto', $entity->Id );
    }

    $attributes = $entity->Attributes;

    foreach ( $attributes as $key => $value ) {
        $meta_key = '_ethos_crm:' . $key;
        if ( is_array( $value ) || is_object( $value ) ) {
            $meta_value = json_encode( $value );
        } elseif ( ! empty( $value ) || is_numeric( $value ) ) {
            $meta_value = $value;
        }

        if ( $meta_key && $meta_value ) {
            update_post_meta( $result, $meta_key, $meta_value );
        }
    }

    return $result;

}

function get_crm_projects_by_type( $name, $args = [] ) {
    $get_all_tipodeprojeto = get_crm_entities( 'fut_tipodeprojeto', [ 'per_page' => 100 ] );
    $result = false;
    if(\is_string($name)) {
        $name = [$name];
    }

    if ( $get_all_tipodeprojeto ) {
        foreach ( $get_all_tipodeprojeto as $tipoparceria ) {
            if ( false !== \array_search($tipoparceria->Attributes['fut_name'], $name) ) {
                if ( empty( $args['filters'] ) ) {
                    $args['filters'] = [];
                }
                $args['filters']['fut_lk_tipoparceria'] = $tipoparceria->Id;
                $entities_collection = get_crm_entities( 'fut_projeto', $args );
                $entities = (array) $entities_collection->Entities;
                if(false !== $result) {
                    $result = array_merge($result, $entities);
                } else {
                    $result = $entities;
                }
            }
        }
    }

    return $result;
}

function do_get_crm_events($num_events = 5) {
    $events = get_crm_projects_by_type( ['Evento', 'Conferência'], [ 'per_page' => $num_events ] );
    if ( $events ) {
        $num = count($events);
        $count = 0;
        foreach( $events as $event ) {
            $count++;
            if ( ! event_exists_on_wp( $event->Id ) ) {
                error_log("($count / $num) criando evento {$event->Id}");
                create_event_on_wp( $event );
            }
        }
    }
}

function get_crm_event_meta( $post_id ) {
    $crm_meta = array_filter( get_post_meta( $post_id ), fn( $item ) => strpos( $item, '_ethos_crm:' ) === 0, ARRAY_FILTER_USE_KEY );
    foreach ( $crm_meta as $key => $value ) {
        $crm_meta[str_replace( '_ethos_crm:', '', $key )] = $value[0];
        unset( $crm_meta[$key] );
    }
    return (object) $crm_meta;
}

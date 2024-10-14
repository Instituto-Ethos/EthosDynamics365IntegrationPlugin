<?php

namespace hacklabr;

use \AlexaCRM\Xrm\Entity;

function event_exists_on_wp( string $entity_id ) {
    $args = [
        'post_type'      => 'tribe_events',
        'meta_query'     => [
            [
                'key'     => 'entity_fut_projeto',
                'value'   => $entity_id,
                'compare' => '='
            ]
        ],
        'posts_per_page' => 1,

        // The Events Calendar can add filters that may cause a false mismatch
        'suppress_filters' => true,
        'tribe_remove_date_filters' => true,
        'tribe_suppress_query_filters' => true,
    ];

    $get_events = get_posts( $args );

    if ( count( $get_events ) > 0 ) {
        return $get_events[0]->ID;
    }

    return false;
}

function create_event_on_wp( Entity $entity ) {
    $entity_id = $entity->Id;
    $attributes = $entity->Attributes;

    if ( $existing_id = event_exists_on_wp( $entity_id ) ) {
        return $existing_id;
    }

    if ( ! \class_exists( 'Tribe__Events__API' ) ) {
        return false;
    }

    if ( ! isset( $attributes['fut_dt_realizacao'] ) ) {
        do_action( 'logger', "Não existe a data de realização do evento. Entity ID: $entity_id" );
        return false;
    }

    if ( ! isset( $attributes['fut_dt_dataehoratermino'] ) ) {
        do_action( 'logger', "Não existe a data de término do evento. Entity ID: $entity_id" );
        return false;
    }

    if ( intval( $attributes['statuscode'] ?? 0  ) == 2 ) {
        do_action( 'logger', "O evento está inativo. Entity ID: $entity_id" );
        return false;
    }

    $start_date = format_iso8601_to_events( $attributes['fut_dt_realizacao'] );
    $end_date   = format_iso8601_to_events( $attributes['fut_dt_dataehoratermino'] );
    $title      = $attributes['fut_name'] ?? '';
    $url        = $attributes['fut_st_website'] ?? '';
    $cost       = $attributes['fut_valorinscricao'] ?? '';

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
        'post_title'              => $title,
    ];

    $args = array_filter( $args );
    $result = \Tribe__Events__API::createEvent( $args );

    if ( $result && ! is_wp_error( $result ) ) {
        update_post_meta( $result, 'entity_fut_projeto', $entity_id );
    }

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

function update_event_on_wp( int $post_id, Entity $entity ) {
    $attributes = $entity->Attributes;

    if ( ! \class_exists( 'Tribe__Events__API' ) ) {
        return false;
    }

    if ( intval( $attributes['statuscode'] ?? 0 ) == 2 ) {
        \Tribe__Events__API::deleteEvent( $post_id, true );
        return false;
    }

    $start_date = format_iso8601_to_events( $attributes['fut_dt_realizacao'] );
    $end_date   = format_iso8601_to_events( $attributes['fut_dt_dataehoratermino'] );
    $title      = $attributes['fut_name'] ?? '';
    $url        = $attributes['fut_st_website'] ?? '';
    $cost       = $attributes['fut_valorinscricao'] ?? '';

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
        'post_title'              => $title,
    ];

    $args = array_filter( $args );
    $result = \Tribe__Events__API::updateEvent( $post_id, $args );

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

    /**
     * Updates the post content for an event if the 'fut_txt_descricao' attribute is set and the post content has not been updated before.
     */
    if ( isset( $attributes['fut_txt_descricao'] ) ) {
        $content_updated = get_post_meta( $post_id, '_ethos_content_updated', true );

        if ( ! $content_updated ) {
            $post_content = $attributes['fut_txt_descricao'];

            wp_update_post( [
                'ID'           => $post_id,
                'post_content' => $post_content
            ] );
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

<?php

namespace hacklabr;

/**
 * Retrieves a list of posts based on a given meta key and value.
 *
 * @param string $meta_key The meta key to search for.
 * @param string $meta_value (optional) The meta value to search for. If not provided, all posts with the given meta key will be returned.
 * @param int $limit (optional) The maximum number of posts to return. Default is 10.
 * @return array An array of post objects.
 */
function get_posts_by_meta( $meta_key, $meta_value = '', $limit = 10 ) {
    global $wpdb;

    $sql = "SELECT p.*
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = %s";

    if ( $meta_value !== '' ) {
        $sql .= " AND pm.meta_value = %s";
        $query = $wpdb->prepare( "$sql ORDER BY p.post_date DESC LIMIT %d", $meta_key, $meta_value, $limit );
    } else {
        $query = $wpdb->prepare( "$sql ORDER BY p.post_date DESC LIMIT %d", $meta_key, $limit );
    }

    $posts = $wpdb->get_results( $query );

    return $posts;
}

function update_meta_log_error( $post_id, $error ) {
    update_post_meta( $post_id, 'log_error', $error );
}

/**
 * Updates the 'entity_{$LogicalName}' post meta for the given post ID.
 *
 * @param int $post_id The ID of the post to update.
 * @param string $entity_name The logical name by the entity.
 * @param string $entity_id The ID value by the entity.
 */
function update_entity_on_postmeta( $post_id, $entity_name, $entity_id ) {

    if ( strpos( $entity_name, 'entity_' ) === 0 ) {
        update_post_meta( $post_id, $entity_name, $entity_id );
    } else {
        update_post_meta( $post_id, 'entity_' . $entity_name, $entity_id );
    }

}

function column_set_all() {
    if ( ! class_exists( '\AlexaCRM\Xrm\ColumnSet' ) ) {
        return false;
    }

    $column_set = new \AlexaCRM\Xrm\ColumnSet();
    $column_set->AllColumns = true;

    return $column_set;
}

function get_entity_attibutes( $entity ) {
    $client = get_client_on_dynamics();

    $metadata_registry = new \AlexaCRM\WebAPI\MetadataRegistry( $client );

    $eol = class_exists( '\WP_CLI' ) ? "\n" : '<br/>';

    try {
        $entity_metadata = $metadata_registry->getDefinition( $entity );

        if ( $entity_metadata !== null && isset( $entity_metadata->Attributes ) ) {
            foreach ( $entity_metadata->Attributes as $attribute ) {
                echo "Atributo: " . $attribute->LogicalName . " - Tipo: " . $attribute->AttributeTypeName->Value . $eol;
            }
        } else {
            echo "Não foi possível obter os metadados da entidade.\n";
        }
    } catch ( \Exception $e ) {
        echo "Erro: " . $e->getMessage() . "\n";
    }
}

function array_filter_args( $value ) {
    return !( $value === '' || $value === false );
}

function get_crm_entities( $entity, $args = [] ) {
    $params = wp_parse_args($args, [
        'cache_for' => 6 * HOUR_IN_SECONDS,
        'per_page'   => 100,
        'orderby' => 'createdon',
        'order'   => 'DESC',
        'filters' => [],
    ]);

    $cache_key = 'crm_entities_' . md5( $entity . serialize( $params ) );
    $cached_data = get_transient( $cache_key );

    if ( $cached_data !== false ) {
        return $cached_data;
    }

    try {
        $query = new \AlexaCRM\Xrm\Query\QueryByAttribute( $entity );
        $query->AddOrder(
            $params['orderby'],
            $params['order'] === 'ASC'
                ? \AlexaCRM\Xrm\Query\OrderType::Ascending()
                : \AlexaCRM\Xrm\Query\OrderType::Descending()
            );
        foreach ( $params['filters'] as $attribute => $value ) {
            $query->AddAttributeValue( $attribute, $value );
        }

        $paging_info = new \AlexaCRM\Xrm\Query\PagingInfo();
        $paging_info->Count = $params['per_page'];
        $paging_info->ReturnTotalRecordCount = true;
        $query->PageInfo = $paging_info;

        $client = get_client_on_dynamics();

        if ( $client !== false ) {
            $result = $client->RetrieveMultiple( $query );
            set_transient( $cache_key, $result, $params['cache_for'] );
            return $result;
        }

    } catch ( \Exception $e ) {
        do_action( 'logger', $e->getMessage() );
    }

    return false;
}

function iterate_crm_entities( $entity, $args = [] ) {
    if ( class_exists( '\AlexaCRM\Xrm\Query\QueryByAttribute' ) ) {
        $params = wp_parse_args($args, [
            'per_page' => 100,
            'max_pages' => PHP_INT_MAX,
            'orderby' => 'createdon',
            'order' => 'DESC',
            'filters' => [],
        ]);

        try {
            $query = new \AlexaCRM\Xrm\Query\QueryByAttribute( $entity );
            $query->AddOrder(
                $params['orderby'],
                $params['order'] === 'ASC'
                    ? \AlexaCRM\Xrm\Query\OrderType::Ascending()
                    : \AlexaCRM\Xrm\Query\OrderType::Descending()
                );
            foreach ( $params['filters'] as $attribute => $value ) {
                $query->AddAttributeValue( $attribute, $value );
            }

            $paging_info = new \AlexaCRM\Xrm\Query\PagingInfo();
            $paging_info->Count = $params['per_page'];
            $paging_info->ReturnTotalRecordCount = true;
            $query->PageInfo = $paging_info;

            $client = get_client_on_dynamics();

            if ( $client !== false ) {
                $result = $client->RetrieveMultiple( $query );

                yield from $result->Entities;

                $current_page = 1;

                while ( $result->MoreRecords ) {
                    if ($current_page >= $params['max_pages']) {
                        break;
                    }

                    $current_page++;

                    $paging_info->PagingCookie = $result->PagingCookie;
                    $result = $client->RetrieveMultiple( $query );

                    yield from $result->Entities;
                }
            }
        } catch ( \Exception $e ) {
            do_action( 'logger', $e->getMessage() );
        }
    }
}

function get_crm_entity_by_id( string $entity_name, string $entity_id, $args = [] ) {
    $params = wp_parse_args($args, [
        'cache_for' => 6 * HOUR_IN_SECONDS,
    ]);

    $client = get_client_on_dynamics();

    if ( $client !== false ) {
        $cached_data = get_cached_crm_entity( $entity_name, $entity_id );

        if ( ! empty( $cached_data ) ) {
            return $cached_data;
        }

        $column_set = new \AlexaCRM\Xrm\ColumnSet();
        $column_set->AllColumns = true;

        try {
            $result = $client->Retrieve( $entity_name, $entity_id, $column_set );
            return cache_crm_entity( $result, $params['cache_for'] );
        } catch ( \Exception $e ) {
            do_action( 'logger', $e->getMessage() );
        }
    }

    return false;
}

function cache_crm_entity( \AlexaCRM\Xrm\Entity $entity, int $cache_for = 6 * HOUR_IN_SECONDS ) {
    if ( ! empty( $entity->Id ) ) {
        $cache_key = 'crm_entity_' . ( $entity->LogicalName ?? '' ) . '_' . $entity->Id;
        set_transient( $cache_key, $entity, $cache_for );
    }
    return $entity;
}

function get_cached_crm_entity( string $entity_name, string $entity_id ) {
    $cache_key = 'crm_entity_' .  $entity_name . '_' . $entity_id;
    $entity = get_transient( $cache_key );

    if ( empty( $entity ) ) {
        return null;
    } else {
        assert( $entity instanceof \AlexaCRM\Xrm\Entity );
        return $entity;
    }
}

function get_crm_server_url() {
    $options = get_option( 'msdyncrm_options' );
    return $options['serverUrl'] ?? '';
}

function get_crm_application_id() {
    $options = get_option( 'msdyncrm_options' );
    return $options['applicationId'] ?? '';
}

function get_crm_client_secret() {
    $options = get_option( 'msdyncrm_options' );
    return $options['clientSecret'] ?? '';
}

function format_iso8601_to_events( $date ) {
    $dateTime = new \DateTime( $date, new \DateTimeZone( 'UTC' ) );
    $dateTime->setTimezone( new \DateTimeZone( 'America/Sao_Paulo' ) );

    $eventDate = $dateTime->format( 'Y-m-d' );
    $eventHour = $dateTime->format( 'H' );
    $eventMinute = $dateTime->format( 'i' );
    $eventMeridian = $dateTime->format( 'a' );

    return [
        'EventDate'     => $eventDate,
        'EventHour'     => $eventHour,
        'EventMinute'   => $eventMinute,
        'EventMeridian' => $eventMeridian
    ];
}

function format_currency_value( $value ) {
    $value = str_replace( ['R$', ' '], '', $value );

    $value = str_replace( ',', '.', $value );

    if ( is_numeric( $value ) ) {
        if ( strpos( $value, '.' ) !== false && strlen( substr( strrchr( $value, '.' ), 1 ) ) <= 2 ) {
            $value = number_format( ( float ) $value, 2, ',', '.' );
        } else {
            $value = number_format( ( float ) $value, 2, ',', '.' );
        }
    }

    return $value;
}

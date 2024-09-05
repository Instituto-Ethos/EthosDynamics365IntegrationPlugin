<?php

namespace hacklabr;

use \AlexaCRM\Xrm\Entity;
use \AlexaCRM\Xrm\EntityCollection;
use \AlexaCRM\Xrm\EntityReference;

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

function column_set_all() {
    if ( ! class_exists( '\AlexaCRM\Xrm\ColumnSet' ) ) {
        return false;
    }

    $column_set = new \AlexaCRM\Xrm\ColumnSet();
    $column_set->AllColumns = true;

    return $column_set;
}

function get_entity_attibutes( string $entity ) {
    $client = get_client_on_dynamics();

    $metadata_registry = new \AlexaCRM\WebAPI\MetadataRegistry( $client );

    try {
        $entity_metadata = $metadata_registry->getDefinition( $entity );

        if ( $entity_metadata !== null && isset( $entity_metadata->Attributes ) ) {
            foreach ( $entity_metadata->Attributes as $attribute ) {
                echo $attribute->LogicalName . "\n";

                if ( ! empty( $attribute->Description->UserLocalizedLabel ) ) {
                    echo "\tDescription: " . $attribute->Description->UserLocalizedLabel->Label . "\n";
                }

                echo "\tType: " . $attribute->AttributeTypeName->Value . "\n";

                if ( ! empty( $attribute->Targets ) ) {
                    echo "\tTargets: " . implode( ' | ', $attribute->Targets ) . "\n";
                }

                echo "\tRequired Level: " . $attribute->RequiredLevel->Value . "\n";

                if ( $attribute->AttributeTypeName->Value === 'PicklistType' ) {
                    echo "\tOptions:\n";

                    foreach ( $attribute->OptionSet->Options as $option ) {
                        echo "\t\t" . $option->Value . ' → ' . $option->Label->UserLocalizedLabel->Label . "\n";
                    }
                }
            }
        } else {
            echo "Não foi possível obter os metadados da entidade.\n";
        }
    } catch ( \Exception $e ) {
        echo "Erro: " . $e->getMessage() . "\n";
    }
}

function get_entity_options( string $entity, string $attribute ) {
    $client = get_client_on_dynamics();

    $metadata_registry = new \AlexaCRM\WebAPI\MetadataRegistry( $client );

    $entity_metadata = $metadata_registry->getDefinition( $entity );

    foreach ( $entity_metadata->Attributes as $attr ) {
        if ( $attr->LogicalName === $attribute ) {
            $options = [];

            foreach ( $attr->OptionSet->Options as $option ) {
                $options[ $option->Value ] = $option->Label->UserLocalizedLabel->Label ?? $option->Value;
            }

            return $options;
        }
    }

    return [];
}

function array_filter_args( $value ) {
    return !( $value === '' || $value === false );
}

function create_crm_entity ( string $entity_name, array $attributes = [] ) {
    $entity = new Entity( $entity_name );

    $filtered_attributes = array_filter( $attributes, 'hacklabr\\array_filter_args' );

    foreach ( $filtered_attributes as $key => $value ) {
        $entity[$key] = $value;
    }

    $client = get_client_on_dynamics();

    $entity_id = $client->Create( $entity );

    return $entity_id;
}

function create_crm_reference ( string $entity_name, string $entity_id ) {
    return new EntityReference( $entity_name, $entity_id );
}

function update_crm_entity ( string $entity_name, string $entity_id, array $attributes = [] ) {
    $entity = new Entity( $entity_name, $entity_id );

    $filtered_attributes = array_filter( $attributes, 'hacklabr\\array_filter_args' );

    foreach ( $filtered_attributes as $key => $value ) {
        $entity[$key] = $value;
    }

    $client = get_client_on_dynamics();

    return $client->Update( $entity );
}

function get_crm_entities( string $entity, array $args = [] ) {
    $params = wp_parse_args($args, [
        'cache' => 6 * HOUR_IN_SECONDS,
        'per_page'   => 100,
        'orderby' => 'createdon',
        'order'   => 'DESC',
        'filters' => [],
    ]);

    if ( $params['cache'] !== false ) {
        $cache_key = 'crm_entities_' . md5( $entity . serialize( $params ) );
        $cached_data = get_transient( $cache_key );

        if ( $cached_data !== false ) {
            assert( $cached_data instanceof EntityCollection );
            return $cached_data;
        }
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

            if ( $params['cache'] !== false ) {
                set_transient( $cache_key, $result, $params['cache'] );
            }

            return $result;
        }

    } catch ( \Exception $e ) {
        do_action( 'logger', $e->getMessage() );
    }

    return null;
}

function iterate_crm_entities( string $entity, array $args = [] ) {
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

function get_crm_entity_by_id( string $entity_name, string $entity_id, array $args = [] ) {
    $params = wp_parse_args($args, [
        'cache' => 6 * HOUR_IN_SECONDS,
    ]);

    if ( $params['cache'] !== false ) {
        $cached_data = get_cached_crm_entity( $entity_name, $entity_id );

        if ( ! empty( $cached_data ) ) {
            return $cached_data;
        }
    }

    $client = get_client_on_dynamics();

    if ( $client !== false ) {
        $column_set = new \AlexaCRM\Xrm\ColumnSet();
        $column_set->AllColumns = true;

        try {
            $result = $client->Retrieve( $entity_name, $entity_id, $column_set );

            if ( $params['cache'] !== false ) {
                cache_crm_entity( $result, $params['cache'] );
            }

            return $result;
        } catch ( \Exception $e ) {
            do_action( 'logger', $e->getMessage() );
        }
    }

    return false;
}

function get_crm_entity_cache_key( string $entity_name, string $entity_id ) {
    return 'crm_entity_' . $entity_name . '_' . $entity_id;
}

function cache_crm_entity( Entity|null $entity, int $expiration = 6 * HOUR_IN_SECONDS ) {
    if ( ! empty( $entity ) ) {
        $cache_key = get_crm_entity_cache_key( $entity->LogicalName ?? '', $entity->Id );
        set_transient( $cache_key, $entity, $expiration );
    }
}

function forget_cached_crm_entity( string $entity_name, string $entity_id ) {
    $cache_key = get_crm_entity_cache_key( $entity_name, $entity_id );
    delete_transient( $cache_key );
}

function get_cached_crm_entity( string $entity_name, string $entity_id ) {
    $cache_key = get_crm_entity_cache_key( $entity_name, $entity_id );
    $entity = get_transient( $cache_key );

    if ( empty( $entity ) ) {
        return null;
    } else {
        assert( $entity instanceof Entity );
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

/**
 * Format a CNPJ number.
 *
 * @param string $cnpj The CNPJ number to format.
 * @return string The formatted CNPJ number.
 */
function format_cnpj($cnpj) {
    $cnpj = preg_replace('/\D/', '', $cnpj);

    if (strlen($cnpj) !== 14) {
        return __('Invalid CNPJ number', 'hacklabr');
    }

    $cnpj_masked = preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cnpj);

    return $cnpj_masked;
}

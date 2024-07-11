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

    try {
        $entity_metadata = $metadata_registry->getDefinition( $entity );

        if ( $entity_metadata !== null && isset( $entity_metadata->Attributes ) ) {
            foreach ( $entity_metadata->Attributes as $attribute ) {
                echo "Atributo: " . $attribute->LogicalName . " - Tipo: " . $attribute->AttributeTypeName->Value . "<br/>";
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

function get_crm_data( $entity, $page = 1, $limit = 10 ) {
    if ( class_exists( '\AlexaCRM\Xrm\Query\QueryByAttribute' ) ) {
        try {
            $query = new \AlexaCRM\Xrm\Query\QueryByAttribute( $entity );
            $paging_info = new \AlexaCRM\Xrm\Query\PagingInfo();
            $paging_info->Count = $limit;
            $paging_info->ReturnTotalRecordCount = true;
            $query->PageInfo = $paging_info;

            $client = \AlexaCRM\WebAPI\ClientFactory::createOnlineClient(
                get_server_url(),
                get_application_id(),
                get_client_secret()
            );

            return $client->RetrieveMultiple( $query );
        } catch ( \Exception $e ) {
            do_action( 'qm/debug', $e->getMessage() );
        }
    }

    return false;
}

function get_crm_data_by_id( string $entity_name, string $entity_id ) {

    $client = get_client_on_dynamics();

    if ( $client !== false ) {

        $column_set = new \AlexaCRM\Xrm\ColumnSet();
        $column_set->AllColumns = true;

        try {
            return $client->Retrieve( $entity_name, $entity_id, $column_set );
        } catch ( \Exception $e ) {
            do_action( 'logger', $e->getMessage() );
        }
    }

    return false;
}

function get_server_url() {
    $options = get_option( 'msdyncrm_options' );
    return $options['serverUrl'] ?? '';
}

function get_application_id() {
    $options = get_option( 'msdyncrm_options' );
    return $options['applicationId'] ?? '';
}

function get_client_secret() {
    $options = get_option( 'msdyncrm_options' );
    return $options['clientSecret'] ?? '';
}



<?php

namespace hacklabr\batch;

use \AlexaCRM\WebAPI\OData\Annotation;
use \AlexaCRM\WebAPI\OData\Client as ODataClient;
use \AlexaCRM\WebAPI\SerializationHelper;
use \AlexaCRM\Xrm\Entity;
use \AlexaCRM\Xrm\EntityCollection;
use \AlexaCRM\Xrm\EntityReference;
use \Psr\Http\Message\ResponseInterface;

function build_odata_filter( string $entity_name, array $filters ): string {
    if ( empty( $filters ) ) {
        return '';
    }

    $client = \hacklabr\get_client_on_dynamics();
    if ( $client === false ) {
        return '';
    }

    $metadata = $client->getClient()->getMetadata();
    $entity_map = $metadata->getEntityMap( $entity_name );
    $column_map = array_flip( $entity_map->inboundMap );
    $field_types = $entity_map->fieldTypes;

    $conditions = parse_filter_conditions( $filters, $column_map, $field_types );

    if ( empty( $conditions ) ) {
        return '';
    }

    if ( count( $conditions ) === 1 ) {
        return $conditions[0];
    }

    return '(' . implode( ' and ', $conditions ) . ')';
}

function parse_filter_conditions( array $filters, array $column_map, array $field_types ): array {
    $conditions = [];

    if ( is_associative_filter( $filters ) ) {
        if ( isset( $filters['and'] ) || isset( $filters['or'] ) ) {
            return parse_filter_group( $filters, $column_map, $field_types );
        }

        foreach ( $filters as $field => $value ) {
            $conditions[] = build_filter_condition( $field, 'eq', $value, $column_map, $field_types );
        }

        return $conditions;
    }

    foreach ( $filters as $condition ) {
        if ( ! is_array( $condition ) ) {
            continue;
        }

        if ( isset( $condition['and'] ) || isset( $condition['or'] ) ) {
            $nested = parse_filter_group( $condition, $column_map, $field_types );
            if ( ! empty( $nested ) ) {
                $conditions[] = '(' . implode( ' and ', $nested ) . ')';
            }
            continue;
        }

        if ( ! isset( $condition['field'] ) || ! isset( $condition['value'] ) ) {
            continue;
        }

        $op = $condition['op'] ?? 'eq';
        $conditions[] = build_filter_condition( $condition['field'], $op, $condition['value'], $column_map, $field_types );
    }

    return $conditions;
}

function is_associative_filter( array $array ): bool {
    if ( empty( $array ) ) {
        return false;
    }

    $keys = array_keys( $array );
    return ! is_numeric( $keys[0] ?? null );
}

function parse_filter_group( array $group, array $column_map, array $field_types ): array {
    $conditions = [];

    if ( isset( $group['and'] ) ) {
        $and_conditions = parse_filter_conditions( $group['and'], $column_map, $field_types );
        if ( ! empty( $and_conditions ) ) {
            $conditions[] = '(' . implode( ' and ', $and_conditions ) . ')';
        }
    }

    if ( isset( $group['or'] ) ) {
        $or_conditions = parse_filter_conditions( $group['or'], $column_map, $field_types );
        if ( ! empty( $or_conditions ) ) {
            $conditions[] = '(' . implode( ' or ', $or_conditions ) . ')';
        }
    }

    return $conditions;
}

function build_filter_condition( string $field, string $op, $value, array $column_map, array $field_types ): string {
    $schema_field = $column_map[ $field ] ?? $field;
    $field_type = $field_types[ $field ] ?? '';
    $formatted_value = format_filter_value( $value, $field_type );

    $operator = strtolower( $op );

    switch ( $operator ) {
        case 'startswith':
        case 'contains':
        case 'endswith':
            return sprintf( '%s(%s,%s)', $operator, $schema_field, $formatted_value );
        case 'eq':
        case 'ne':
        case 'gt':
        case 'ge':
        case 'lt':
        case 'le':
            return sprintf( '%s %s %s', $schema_field, $operator, $formatted_value );
        default:
            return sprintf( '%s eq %s', $schema_field, $formatted_value );
    }
}

function format_filter_value( $value, string $field_type ): string {
    if ( $value === null ) {
        return 'null';
    }

    if ( $field_type === 'Edm.String' ) {
        $string_value = (string) $value;
        $escaped = str_replace( "'", "''", $string_value );
        return "'" . $escaped . "'";
    }

    if ( is_bool( $value ) ) {
        return $value ? 'true' : 'false';
    }

    if ( $value instanceof \DateTimeInterface ) {
        return "'" . $value->format( 'c' ) . "'";
    }

    if ( is_numeric( $value ) ) {
        return (string) $value;
    }

    if ( is_guid( $value ) || $field_type === 'Edm.Guid' ) {
        return (string) $value;
    }

    $string_value = (string) $value;
    $escaped = str_replace( "'", "''", $string_value );
    return "'" . $escaped . "'";
}

function is_guid( $value ): bool {
    if ( ! is_string( $value ) ) {
        return false;
    }

    return preg_match( '/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $value ) === 1;
}

function get_crm_entities_advanced( string $entity, array $filters, array $args = [] ): EntityCollection {
    $params = wp_parse_args( $args, [
        'cache'    => 6 * HOUR_IN_SECONDS,
        'per_page' => 100,
        'orderby'  => 'createdon',
        'order'    => 'DESC',
        'select'   => [],
    ] );

    if ( $params['cache'] !== false ) {
        $cache_key = 'crm_entities_advanced_' . md5( $entity . serialize( $params ) );
        $cached_data = get_transient( $cache_key );

        if ( $cached_data !== false && $cached_data instanceof EntityCollection ) {
            return $cached_data;
        }
    }

    $collection = new EntityCollection();
    $collection->EntityName = $entity;
    $collection->MoreRecords = false;
    $collection->TotalRecordCount = -1;
    $collection->TotalRecordCountLimitExceeded = false;

    $client = \hacklabr\get_client_on_dynamics();
    if ( $client === false ) {
        return $collection;
    }

    try {
        $odata_client = $client->getClient();
        $metadata = $odata_client->getMetadata();
        $collection_name = $metadata->getEntitySetName( $entity );
        $entity_map = $metadata->getEntityMap( $entity );
        $column_map = array_flip( $entity_map->inboundMap );

        $query_options = [];

        if ( ! empty( $filters ) ) {
            $query_options['Filter'] = build_odata_filter( $entity, $filters );
        }

        if ( $params['per_page'] > 0 ) {
            $query_options['Top'] = $params['per_page'];
        }

        if ( ! empty( $params['orderby'] ) ) {
            $schema_order = $column_map[ $params['orderby'] ] ?? $params['orderby'];
            $query_options['OrderBy'][] = $schema_order . ' ' . strtolower( $params['order'] );
        }

        if ( ! empty( $params['select'] ) ) {
            $select_fields = array_map( function ( $field ) use ( $column_map ) {
                return $column_map[ $field ] ?? $field;
            }, $params['select'] );

            $select_fields[] = $entity_map->key;
            $query_options['Select'] = array_unique( $select_fields );
        }

        $response = $odata_client->getList( $collection_name, $query_options );

        $serializer = new SerializationHelper( $odata_client );
        $collection->TotalRecordCount = $response->TotalRecordCount;
        $collection->TotalRecordCountLimitExceeded = $response->TotalRecordCountLimitExceeded;

        foreach ( $response->List as $item ) {
            $ref = new EntityReference( $entity );
            if ( property_exists( $item, $entity_map->key ) ) {
                $ref->Id = $item->{$entity_map->key};
            }

            $record = $serializer->deserializeEntity( $item, $ref );
            $collection->Entities[] = $record;
        }

        if ( $params['cache'] !== false && count( $collection->Entities ) > 0 ) {
            set_transient( $cache_key, $collection, $params['cache'] );
        }
    } catch ( \Exception $e ) {
        do_action( 'logger', $e->getMessage() );
    }

    return $collection;
}

class Dynamics_Batch_Reference {
    private string $content_id;

    public function __construct( string $content_id ) {
        $this->content_id = $content_id;
    }

    public function __toString(): string {
        return '$' . $this->content_id;
    }

    public function get_content_id(): string {
        return $this->content_id;
    }
}

class Dynamics_Batch_Builder {
    public const MAX_REQUESTS_PER_BATCH = 1000;

    public const NO_RESPONSE_MESSAGE = 'No response received (batch processing stopped at an earlier failed request)';

    private ODataClient $client;
    private array $operations = [];
    private int $content_id_counter = 1;
    private array $results_by_content_id = [];

    public function __construct( ODataClient $client ) {
        $this->client = $client;
    }

    public function add_query( string $entity_name, array $filters, array $options = [] ): self {
        $this->operations[] = [
            'type'        => 'query',
            'entity_name' => $entity_name,
            'filters'     => $filters,
            'options'     => $options,
        ];

        return $this;
    }

    public function add_create( string $entity_name, array $attributes ): Dynamics_Batch_Reference {
        $content_id = (string) $this->content_id_counter++;

        $this->operations[] = [
            'type'        => 'create',
            'entity_name' => $entity_name,
            'attributes'  => $attributes,
            'content_id'  => $content_id,
        ];

        return new Dynamics_Batch_Reference( $content_id );
    }

    public function add_update( string $entity_name, string $entity_id, array $attributes ): Dynamics_Batch_Reference {
        $content_id = (string) $this->content_id_counter++;

        $this->operations[] = [
            'type'        => 'update',
            'entity_name' => $entity_name,
            'entity_id'   => $entity_id,
            'attributes'  => $attributes,
            'content_id'  => $content_id,
        ];

        return new Dynamics_Batch_Reference( $content_id );
    }

    public function add_delete( string $entity_name, string $entity_id ): Dynamics_Batch_Reference {
        $content_id = (string) $this->content_id_counter++;

        $this->operations[] = [
            'type'        => 'delete',
            'entity_name' => $entity_name,
            'entity_id'   => $entity_id,
            'content_id'  => $content_id,
        ];

        return new Dynamics_Batch_Reference( $content_id );
    }

    public function add_associate( string $entity_name, string $entity_id, string $relationship, string $related_entity_name, string $related_entity_id ): Dynamics_Batch_Reference {
        $content_id = (string) $this->content_id_counter++;

        $this->operations[] = [
            'type'              => 'associate',
            'entity_name'         => $entity_name,
            'entity_id'           => $entity_id,
            'relationship'        => $relationship,
            'related_entity_name' => $related_entity_name,
            'related_entity_id'   => $related_entity_id,
            'content_id'          => $content_id,
        ];

        return new Dynamics_Batch_Reference( $content_id );
    }

    public function execute( bool $transactional = false ): array {
        $results = [];
        $chunks = array_chunk( $this->operations, self::MAX_REQUESTS_PER_BATCH );

        foreach ( $chunks as $chunk ) {
            $chunk_results = $this->execute_chunk( $chunk, $transactional );
            $results = array_merge( $results, $this->annotate_aborted_operations( $chunk_results ) );
        }

        $this->index_results( $results );
        $this->log_failed_batch( $results );

        return $results;
    }

    public function get_result( Dynamics_Batch_Reference $ref ): ?array {
        return $this->results_by_content_id[ $ref->get_content_id() ] ?? null;
    }

    private function index_results( array $results ): void {
        foreach ( $results as $result ) {
            $op_index = $result['index'] ?? null;
            if ( $op_index === null || ! isset( $this->operations[ $op_index ] ) ) {
                continue;
            }

            $content_id = $this->operations[ $op_index ]['content_id'] ?? null;
            if ( $content_id !== null ) {
                $this->results_by_content_id[ $content_id ] = $result;
            }
        }
    }

    private function log_failed_batch( array $results ): void {
        $error_count = 0;
        $lines = [];

        foreach ( $results as $result ) {
            if ( $result['status'] !== 'success' ) {
                $error_count++;
            }

            $lines[] = sprintf(
                '[%d] %s %s: %s%s',
                $result['index'],
                $result['type'],
                $result['entity'] ?? '(desconhecida)',
                $result['status'],
                $result['message'] ? ' | ' . $result['message'] : ''
            );
        }

        if ( $error_count === 0 ) {
            return;
        }

        do_action(
            'logger',
            sprintf(
                "Lote Dynamics 365 concluido com %d de %d operacoes com erro:\n%s",
                $error_count,
                count( $results ),
                implode( "\n", $lines )
            )
        );
    }

    private function annotate_aborted_operations( array $results ): array {
        $first_failure_index = null;
        $first_failure_message = null;

        foreach ( $results as $result ) {
            if ( $result['status'] === 'error' && $result['message'] !== self::NO_RESPONSE_MESSAGE ) {
                $first_failure_index = $result['index'];
                $first_failure_message = $result['message'];
                break;
            }
        }

        if ( $first_failure_index === null ) {
            return $results;
        }

        /*
         * Toda operacao sem resposta e explicada pelo abort do Dataverse
         * (a ordem de execucao, escritas antes das queries, pode diferir da
         * ordem de insercao). Repassa a mensagem da causa raiz para que o
         * consumidor de qualquer resultado veja o erro original, sem precisar
         * localizar a primeira operacao com falha. O logger continua exibindo
         * o resultado completo, com indices e status por operacao.
         */
        foreach ( $results as $key => $result ) {
            if ( $result['message'] === self::NO_RESPONSE_MESSAGE ) {
                $results[ $key ]['message'] = $first_failure_message;
            }
        }

        return $results;
    }

    private function execute_chunk( array $operations, bool $transactional ): array {
        $batch_boundary = 'batch_' . uniqid();
        $changeset_boundary = 'changeset_' . uniqid();

        $body = $this->build_batch_body( $operations, $transactional, $batch_boundary, $changeset_boundary );

        $settings = $this->client->getSettings();
        $endpoint = $settings->getEndpointURI();
        $url = $endpoint . '$batch';

        $headers = [
            'Content-Type' => 'multipart/mixed; boundary=' . $batch_boundary,
            'Accept'       => 'application/json',
        ];

        $http_client = $this->client->getHttpClient();
        try {
            $response = $http_client->request( 'POST', $url, [
                'headers' => $headers,
                'body'    => $body,
            ] );
        } catch ( \GuzzleHttp\Exception\RequestException $e ) {
            $response = $e->getResponse();
            if ( $response === null ) {
                throw $e;
            }
        }

        return $this->parse_batch_response( $response, $operations );
    }

    private function build_batch_body( array $operations, bool $transactional, string $batch_boundary, string $changeset_boundary ): string {
        $parts = [];
        $write_operations = [];
        $query_operations = [];

        foreach ( $operations as $index => $operation ) {
            if ( $operation['type'] === 'query' ) {
                $query_operations[] = $index;
            } else {
                $write_operations[] = $index;
            }
        }

        if ( ! empty( $write_operations ) ) {
            if ( $transactional ) {
                $parts[] = '--' . $batch_boundary . "\r\n";
                $parts[] = 'Content-Type: multipart/mixed; boundary=' . $changeset_boundary . "\r\n";
                $parts[] = "\r\n";

                foreach ( $write_operations as $index ) {
                    $parts[] = '--' . $changeset_boundary . "\r\n";
                    $parts[] = $this->build_operation_part( $operations[ $index ], true );
                }

                $parts[] = '--' . $changeset_boundary . "--\r\n";
                $parts[] = "\r\n";
            } else {
                foreach ( $write_operations as $sequence => $index ) {
                    $single_changeset_boundary = $changeset_boundary . '_' . $sequence;

                    $parts[] = '--' . $batch_boundary . "\r\n";
                    $parts[] = 'Content-Type: multipart/mixed; boundary=' . $single_changeset_boundary . "\r\n";
                    $parts[] = "\r\n";
                    $parts[] = '--' . $single_changeset_boundary . "\r\n";
                    $parts[] = $this->build_operation_part( $operations[ $index ], true );
                    $parts[] = '--' . $single_changeset_boundary . "--\r\n";
                    $parts[] = "\r\n";
                }
            }
        }

        foreach ( $query_operations as $index ) {
            $parts[] = '--' . $batch_boundary . "\r\n";
            $parts[] = $this->build_operation_part( $operations[ $index ], false );
        }

        $parts[] = '--' . $batch_boundary . "--\r\n";

        return implode( '', $parts );
    }

    private function build_operation_part( array $operation, bool $in_changeset ): string {
        $metadata = $this->client->getMetadata();
        $collection_name = $metadata->getEntitySetName( $operation['entity_name'] );
        $endpoint = $this->client->getSettings()->getEndpointURI();

        $content_id = $operation['content_id'] ?? null;
        $content_id_header = ( $in_changeset && $content_id ) ? 'Content-ID: ' . $content_id . "\r\n" : '';

        switch ( $operation['type'] ) {
            case 'query':
                return $this->build_query_part( $operation, $collection_name, $endpoint );
            case 'create':
                return $this->build_create_part( $operation, $collection_name, $endpoint, $content_id_header );
            case 'update':
                return $this->build_update_part( $operation, $collection_name, $endpoint, $content_id_header );
            case 'delete':
                return $this->build_delete_part( $operation, $collection_name, $endpoint, $content_id_header );
            case 'associate':
                return $this->build_associate_part( $operation, $collection_name, $endpoint, $content_id_header );
        }

        return '';
    }

    private function build_query_part( array $operation, string $collection_name, string $endpoint ): string {
        $metadata = $this->client->getMetadata();
        $entity_map = $metadata->getEntityMap( $operation['entity_name'] );
        $column_map = array_flip( $entity_map->inboundMap );

        $query_options = [];

        if ( ! empty( $operation['filters'] ) ) {
            $query_options['Filter'] = build_odata_filter( $operation['entity_name'], $operation['filters'] );
        }

        if ( ! empty( $operation['options']['per_page'] ) ) {
            $query_options['Top'] = $operation['options']['per_page'];
        }

        if ( ! empty( $operation['options']['orderby'] ) ) {
            $schema_order = $column_map[ $operation['options']['orderby'] ] ?? $operation['options']['orderby'];
            $order = strtolower( $operation['options']['order'] ?? 'desc' );
            $query_options['OrderBy'][] = $schema_order . ' ' . $order;
        }

        if ( ! empty( $operation['options']['select'] ) ) {
            $query_options['Select'] = array_map( function ( $field ) use ( $column_map ) {
                return $column_map[ $field ] ?? $field;
            }, $operation['options']['select'] );
        }

        $url = $this->build_query_url( $collection_name, $query_options, $endpoint );

        $part = "Content-Type: application/http\r\n";
        $part .= "Content-Transfer-Encoding: binary\r\n";
        $part .= "\r\n";
        $part .= "GET " . $url . " HTTP/1.1\r\n";
        $part .= "Accept: application/json\r\n";
        $part .= "OData-MaxVersion: 4.0\r\n";
        $part .= "OData-Version: 4.0\r\n";
        $part .= "\r\n";

        return $part;
    }

    private function build_query_url( string $collection_name, array $query_options, string $endpoint ): string {
        $query_parameters = [];

        if ( isset( $query_options['Select'] ) && count( $query_options['Select'] ) ) {
            $query_parameters['$select'] = implode( ',', $query_options['Select'] );
        }

        if ( isset( $query_options['OrderBy'] ) && count( $query_options['OrderBy'] ) ) {
            $query_parameters['$orderby'] = implode( ',', $query_options['OrderBy'] );
        }

        if ( isset( $query_options['Filter'] ) ) {
            $query_parameters['$filter'] = $query_options['Filter'];
        }

        if ( isset( $query_options['Top'] ) ) {
            $query_parameters['$top'] = $query_options['Top'];
        }

        $url = $endpoint . $collection_name;

        if ( ! empty( $query_parameters ) ) {
            $url .= '?' . http_build_query( $query_parameters, '', '&', PHP_QUERY_RFC3986 );
        }

        return $url;
    }

    private function build_create_part( array $operation, string $collection_name, string $endpoint, string $content_id_header ): string {
        $data = $this->serialize_attributes( $operation['entity_name'], $operation['attributes'] );

        $part = "Content-Type: application/http\r\n";
        $part .= "Content-Transfer-Encoding: binary\r\n";
        if ( ! empty( $content_id_header ) ) {
            $part .= $content_id_header;
        }
        $part .= "\r\n";
        $part .= "POST " . $endpoint . $collection_name . " HTTP/1.1\r\n";
        $part .= "Content-Type: application/json\r\n";
        $part .= "OData-MaxVersion: 4.0\r\n";
        $part .= "OData-Version: 4.0\r\n";
        $part .= "\r\n";
        $part .= json_encode( $data ) . "\r\n";

        return $part;
    }

    private function build_update_part( array $operation, string $collection_name, string $endpoint, string $content_id_header ): string {
        $data = $this->serialize_attributes( $operation['entity_name'], $operation['attributes'] );

        $part = "Content-Type: application/http\r\n";
        $part .= "Content-Transfer-Encoding: binary\r\n";
        if ( ! empty( $content_id_header ) ) {
            $part .= $content_id_header;
        }
        $part .= "\r\n";
        $part .= "PATCH " . $endpoint . $collection_name . '(' . $operation['entity_id'] . ") HTTP/1.1\r\n";
        $part .= "Content-Type: application/json\r\n";
        $part .= "OData-MaxVersion: 4.0\r\n";
        $part .= "OData-Version: 4.0\r\n";
        $part .= "\r\n";
        $part .= json_encode( $data ) . "\r\n";

        return $part;
    }

    private function build_delete_part( array $operation, string $collection_name, string $endpoint, string $content_id_header ): string {
        $part = "Content-Type: application/http\r\n";
        $part .= "Content-Transfer-Encoding: binary\r\n";
        if ( ! empty( $content_id_header ) ) {
            $part .= $content_id_header;
        }
        $part .= "\r\n";
        $part .= "DELETE " . $endpoint . $collection_name . '(' . $operation['entity_id'] . ") HTTP/1.1\r\n";
        $part .= "OData-MaxVersion: 4.0\r\n";
        $part .= "OData-Version: 4.0\r\n";
        $part .= "\r\n";

        return $part;
    }

    private function build_associate_part( array $operation, string $collection_name, string $endpoint, string $content_id_header ): string {
        $related_collection_name = $this->client->getMetadata()->getEntitySetName( $operation['related_entity_name'] );
        $url = $endpoint . $collection_name . '(' . $operation['entity_id'] . ')/' . $operation['relationship'] . '/$ref';
        $data = [
            Annotation::ODATA_ID => $endpoint . $related_collection_name . '(' . $operation['related_entity_id'] . ')',
        ];

        $part = "Content-Type: application/http\r\n";
        $part .= "Content-Transfer-Encoding: binary\r\n";
        if ( ! empty( $content_id_header ) ) {
            $part .= $content_id_header;
        }
        $part .= "\r\n";
        $part .= "POST " . $url . " HTTP/1.1\r\n";
        $part .= "Content-Type: application/json\r\n";
        $part .= "OData-MaxVersion: 4.0\r\n";
        $part .= "OData-Version: 4.0\r\n";
        $part .= "\r\n";
        $part .= json_encode( $data ) . "\r\n";

        return $part;
    }

    private function serialize_attributes( string $entity_name, array $attributes ): array {
        $entity = new Entity( $entity_name );
        $reference_fields = [];
        $explicit_annotations = [];

        foreach ( $attributes as $key => $value ) {
            if ( str_ends_with( $key, Annotation::ODATA_BIND ) ) {
                $explicit_annotations[ $key ] = (string) $value;
            } elseif ( $value instanceof Dynamics_Batch_Reference ) {
                $reference_fields[ $key ] = (string) $value;
            } else {
                $entity[ $key ] = $value;
            }
        }

        $serializer = new SerializationHelper( $this->client );
        $data = $serializer->serializeEntity( $entity );

        $data = array_merge( $data, $explicit_annotations );

        if ( ! empty( $reference_fields ) ) {
            $metadata = $this->client->getMetadata();
            $entity_map = $metadata->getEntityMap( $entity_name );
            $outbound_map = $entity_map->outboundMap;

            foreach ( $reference_fields as $field => $reference ) {
                if ( isset( $outbound_map[ $field ] ) && is_array( $outbound_map[ $field ] ) ) {
                    $nav_property = array_values( $outbound_map[ $field ] )[0];
                    $data[ $nav_property . Annotation::ODATA_BIND ] = $reference;
                }
            }
        }

        return $data;
    }

    private function parse_batch_response( ResponseInterface $response, array $operations ): array {
        $content_type = $response->getHeaderLine( 'Content-Type' );
        $boundary = $this->extract_boundary( $content_type );
        $body = (string) $response->getBody();

        $results = [];
        foreach ( $operations as $index => $operation ) {
            $results[ $index ] = [
                'type'      => $operation['type'],
                'entity'    => $operation['entity_name'],
                'status'    => 'error',
                'entity_id' => null,
                'data'      => null,
                'message'   => self::NO_RESPONSE_MESSAGE,
                'index'     => $index,
            ];
        }

        if ( empty( $boundary ) ) {
            return $results;
        }

        $write_indexes = [];
        $query_indexes = [];
        foreach ( $operations as $index => $operation ) {
            if ( $operation['type'] === 'query' ) {
                $query_indexes[] = $index;
            } else {
                $write_indexes[] = $index;
            }
        }

        $write_response_parts = [];
        $query_response_parts = [];

        foreach ( $this->parse_multipart( $body, $boundary ) as $part ) {
            if ( empty( $part['body'] ) ) {
                continue;
            }

            if ( str_starts_with( $part['content_type'] ?? '', 'multipart/mixed' ) ) {
                $nested_boundary = $this->extract_boundary( $part['content_type'] );
                foreach ( $this->parse_multipart( $part['body'], $nested_boundary ) as $nested_part ) {
                    if ( ! empty( $nested_part['body'] ) ) {
                        $write_response_parts[] = $nested_part;
                    }
                }
            } else {
                $query_response_parts[] = $part;
            }
        }

        foreach ( $write_response_parts as $sequence => $part ) {
            if ( ! isset( $write_indexes[ $sequence ] ) ) {
                break;
            }
            $index = $write_indexes[ $sequence ];
            $results[ $index ] = $this->parse_response_part( $part, $operations[ $index ], $index );
        }

        foreach ( $query_response_parts as $sequence => $part ) {
            if ( ! isset( $query_indexes[ $sequence ] ) ) {
                break;
            }
            $index = $query_indexes[ $sequence ];
            $results[ $index ] = $this->parse_response_part( $part, $operations[ $index ], $index );
        }

        return $results;
    }

    private function parse_response_part( array $part, array $operation, int $index ): array {
        $result = [
            'type'      => $operation['type'],
            'entity'    => $operation['entity_name'],
            'status'    => 'error',
            'entity_id' => null,
            'data'      => null,
            'message'   => '',
            'index'     => $index,
        ];

        $body = $part['body'] ?? '';
        $lines = explode( "\r\n", $body );
        $status_line = '';
        $headers = [];
        $response_body = '';
        $in_headers = true;

        foreach ( $lines as $line ) {
            if ( $in_headers ) {
                if ( $line === '' ) {
                    $in_headers = false;
                    continue;
                }

                if ( empty( $status_line ) && strpos( $line, 'HTTP/' ) === 0 ) {
                    $status_line = $line;
                    continue;
                }

                $colon_pos = strpos( $line, ':' );
                if ( $colon_pos !== false ) {
                    $header_name = strtolower( trim( substr( $line, 0, $colon_pos ) ) );
                    $header_value = trim( substr( $line, $colon_pos + 1 ) );
                    $headers[ $header_name ] = $header_value;
                }
            } else {
                $response_body .= $line . "\r\n";
            }
        }

        $response_body = trim( $response_body );
        $status_code = 0;
        if ( preg_match( '/HTTP\/\d\.\d (\d{3})/', $status_line, $matches ) ) {
            $status_code = (int) $matches[1];
        }

        if ( $status_code >= 200 && $status_code < 300 ) {
            $result['status'] = 'success';
        } elseif ( $status_code === 404 && $operation['type'] === 'query' ) {
            $result['status'] = 'success';
            $result['data'] = new EntityCollection();
        } else {
            $result['status'] = 'error';
            $result['message'] = $this->extract_error_message( $response_body );
        }

        if ( $result['status'] === 'success' ) {
            switch ( $operation['type'] ) {
                case 'create':
                case 'update':
                    $result['entity_id'] = $this->extract_entity_id_from_headers( $headers ) ?? $operation['entity_id'] ?? null;
                    break;
                case 'query':
                    $result['data'] = $this->deserialize_query_response( $operation['entity_name'], $response_body );
                    break;
                case 'associate':
                case 'delete':
                    break;
            }
        }

        return $result;
    }

    private function extract_entity_id_from_headers( array $headers ): ?string {
        if ( ! isset( $headers['odata-entity-id'] ) ) {
            return null;
        }

        $header_value = $headers['odata-entity-id'];
        $id = substr( $header_value, strrpos( $header_value, '(' ) + 1, 36 );
        return $id === false ? null : $id;
    }

    private function deserialize_query_response( string $entity_name, string $response_body ): EntityCollection {
        $collection = new EntityCollection();
        $collection->EntityName = $entity_name;
        $collection->MoreRecords = false;
        $collection->TotalRecordCount = -1;

        $data = json_decode( $response_body );
        if ( ! is_object( $data ) || ! isset( $data->value ) ) {
            return $collection;
        }

        $metadata = $this->client->getMetadata();
        $entity_map = $metadata->getEntityMap( $entity_name );
        $serializer = new SerializationHelper( $this->client );

        foreach ( $data->value as $item ) {
            $ref = new EntityReference( $entity_name );
            if ( property_exists( $item, $entity_map->key ) ) {
                $ref->Id = $item->{$entity_map->key};
            }

            $record = $serializer->deserializeEntity( $item, $ref );
            $collection->Entities[] = $record;
        }

        return $collection;
    }

    private function extract_error_message( string $response_body ): string {
        $data = json_decode( $response_body );
        if ( isset( $data->error->message ) ) {
            return $data->error->message;
        }

        return $response_body;
    }

    private function extract_boundary( string $content_type ): string {
        if ( preg_match( '/boundary=([^;\s]+)/i', $content_type, $matches ) ) {
            return trim( $matches[1], '"\'' );
        }

        return '';
    }

    private function parse_multipart( string $body, string $boundary ): array {
        $parts = [];
        $delimiter = '--' . $boundary;
        $segments = explode( $delimiter, $body );

        foreach ( $segments as $segment ) {
            $segment = trim( $segment );
            if ( $segment === '' || $segment === '--' ) {
                continue;
            }

            $segment = ltrim( $segment, "\r\n" );
            $empty_line = strpos( $segment, "\r\n\r\n" );

            if ( $empty_line === false ) {
                continue;
            }

            $header_text = substr( $segment, 0, $empty_line );
            $body_text = substr( $segment, $empty_line + 4 );

            $headers = [];
            foreach ( explode( "\r\n", $header_text ) as $line ) {
                $colon_pos = strpos( $line, ':' );
                if ( $colon_pos !== false ) {
                    $header_name = strtolower( trim( substr( $line, 0, $colon_pos ) ) );
                    $header_value = trim( substr( $line, $colon_pos + 1 ) );
                    $headers[ $header_name ] = $header_value;
                }
            }

            $parts[] = [
                'content_type' => $headers['content-type'] ?? '',
                'body'         => rtrim( $body_text, "\r\n" ),
            ];
        }

        return $parts;
    }
}

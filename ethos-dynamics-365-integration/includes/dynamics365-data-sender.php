<?php

namespace hacklabr;

function add_to_sync_waiting_list( $post_id ) {
    $waiting_list = get_option( '_ethos_sync_waiting_list', [] );

    if ( in_array( $post_id, $waiting_list ) ) {
        return;
    }

    $waiting_list[] = $post_id;

    update_option( '_ethos_sync_waiting_list', $waiting_list );
}

function remove_from_sync_waiting_list( $post_id ) {
    $waiting_list = get_option( '_ethos_sync_waiting_list', [] );

    if ( ( $key = array_search( $post_id, $waiting_list ) ) !== false ) {
        unset( $waiting_list[$key] );
        update_option( '_ethos_sync_waiting_list', $waiting_list );
    }
}

function add_to_approval_waiting( $post_id ) {
    $waiting_approval = get_option( '_ethos_waiting_approval', [] );

    if ( in_array( $post_id, $waiting_approval ) ) {
        return;
    }

    $waiting_approval[] = $post_id;

    update_option( '_ethos_waiting_approval', $waiting_approval );
}

function remove_from_approval_waiting( $post_id ) {
    $waiting_approval = get_option( '_ethos_waiting_approval', [] );

    if ( ( $key = array_search( $post_id, $waiting_approval ) ) !== false ) {
        unset( $waiting_approval[$key] );
        update_option( '_ethos_waiting_approval', $waiting_approval );
    }
}

function sync_entity( $post_id ) {
    $waiting_list = get_option( '_ethos_sync_waiting_list', [] );

    if ( ( array_search( $post_id, $waiting_list ) ) !== false ) {
        // sync here
        $send_account_to_crm = send_lead_to_crm( $post_id );

        if ( $send_account_to_crm['status'] === 'success' ) {
            // salva o relacionamento da entidade no post
            update_entity_on_postmeta( $post_id, 'lead', $send_account_to_crm['entity_id'] );

            // apaga o erro de log do post
            \delete_post_meta( $post_id, 'log_error' );

            // remove post da lista de sincronização
            remove_from_sync_waiting_list( $post_id );

            // adiciona post na lista de aprovação
            add_to_approval_waiting( $post_id );
        } else {
            // salva o erro de log no data_logger
            do_action( 'logger', $send_account_to_crm['message'] );

            // salva o erro de log no post
            update_meta_log_error( $post_id, $send_account_to_crm['message'] );
        }
    }
}

add_action( 'sync_entity', 'hacklabr\sync_entity', 10 );

function approval_entity( $post_id ) {
    $waiting_approval = get_option( '_ethos_waiting_approval', [] );

    if ( ( array_search( $post_id, $waiting_approval ) ) !== false ) {
        $get_entity_id = get_post_meta( $post_id, 'entity_lead', true );
        $get_crm_data_by_id = get_crm_entity_by_id( 'lead', $get_entity_id );

        if ( $get_crm_data_by_id && $get_crm_data_by_id->GetAttributeValue( 'parentaccountid' ) ) {
            $parent_account_id = $get_crm_data_by_id->GetAttributeValue( 'parentaccountid' );

            if ( $parent_account_id->Id ) {
                // salva o relacionamento da entidade no post
                update_entity_on_postmeta( $post_id, $parent_account_id->LogicalName, $parent_account_id->Id );

                // apaga o erro de log do post
                \delete_post_meta( $post_id, 'log_error' );

                // remove post da lista de aprovação
                remove_from_approval_waiting( $post_id );

                $group_id = get_post_meta( $post_id, '_pmpro_group', true );

                if ( ! empty( $group_id ) ) {
                    $group = get_pmpro_group( intval( $group_id ) );
                    $user_id = $group->group_parent_user_id;
                    $level_id = $group->group_parent_level_id;
                    $pmpro_approvals = \PMPro_Approvals::approveMember( $user_id, $level_id, true );

                    // salva o id do contato do CRM no usuário do WP
                    update_user_meta( $user_id, '_ethos_crm_contact_id', $parent_account_id->Id );

                    if ( ! $pmpro_approvals ) {
                        update_user_meta( $user_id, 'log_error', [
                            'message' => 'Erro ao aprovar usuário',
                            'group' => $group
                        ] );
                    }
                } else {
                    // salva o erro de log no user
                    update_post_meta( $post_id, 'log_error', 'Erro no grupo' );
                }
            }
        }
    }
}

add_action( 'approval_entity', 'hacklabr\approval_entity', 10 );

function add_post_to_sync_waiting_list( $post_id ) {
    $_pmpro_group = get_post_meta( $post_id, '_pmpro_group', true );
    $get_entity_id = get_post_meta( $post_id, 'entity_lead', true );
    $is_imported = get_post_meta( $post_id, '_ethos_from_crm', true );

    if ( $_pmpro_group && ! $get_entity_id && ! $is_imported ) {
        add_to_sync_waiting_list( $post_id );
    }

}

add_action( 'save_post_organizacao', 'hacklabr\add_post_to_sync_waiting_list', 10, 1 );

function cancel_sync( $post_id ) {
    remove_from_sync_waiting_list( $post_id );
    remove_from_approval_waiting( $post_id );
}

function send_account_to_crm( $post_id ) {
    $post_meta = get_post_meta( $post_id );

    // Required fields
    $name = $post_meta['nome_fantasia'][0] ?? '';
    $cnpj = $post_meta['cnpj'][0] ?? '';

    if ( $name && $cnpj ) {
        $entity                = new \AlexaCRM\Xrm\Entity( 'lead' );
        $entity['name']        = $name;
        $entity['fut_st_cnpj'] = $cnpj;

        if ( isset( $post_meta['razao_social'][0] ) ) {
            $entity['fut_st_razaosocial'] = $post_meta['fut_st_razaosocial'][0];
        }

        if ( isset( $post_meta['inscricao_estadual'][0] ) ) {
            $entity['fut_st_inscricaoestadual'] = $post_meta['inscricao_estadual'][0];
        }

        if ( isset( $post_meta['inscricao_municipal'][0] ) ) {
            $entity['fut_st_inscricaomunicipal'] = $post_meta['inscricao_municipal'][0];
        }

        if ( isset( $post_meta['website'][0] ) ) {
            $entity['websiteurl'] = $post_meta['website'][0];
        }

        if ( isset( $post_meta['segmento'][0] ) ) {
            $entity['fut_lk_setor'] = $post_meta['segmento'][0];
        }

        try {

            $client = get_client_on_dynamics();

            $entityId = $client->Create( $entity );
            return [
                'status'    => 'success',
                'message'   => 'Entidade criada com sucesso no CRM.',
                'entity_id' => $entityId
            ];

        } catch ( \AlexaCRM\WebAPI\ODataException $e ) {

            return [
                'status'  => 'error',
                'message' => "Ocorreu um erro ao criar a entidade no CRM (Post ID $post_id): " . $e->getMessage()
            ];

        } catch ( \AlexaCRM\WebAPI\EntityNotSupportedException $e ) {

            return [
                'status'  => 'error',
                'message' => "Entidade `{$entity->LogicalName}` não é suportada pelo CRM (Post ID $post_id)."
            ];

        } catch ( \AlexaCRM\WebAPI\TransportException $e ) {

            return [
                'status'  => 'error',
                'message' => "Erro de transporte ao comunicar com o CRM (Post ID $post_id): " . $e->getMessage()
            ];

        } catch ( \Exception $e ) {

            return [
                'status'  => 'error',
                'message' => "Erro inesperado (Post ID $post_id): " . $e->getMessage()
            ];

        }
    }

    update_meta_log_error( $post_id, 'Dados insuficientes para criar a entidade no CRM.' );
    return [
        'status'  => 'error',
        'message' => "Dados insuficientes para criar a entidade no CRM (Post ID $post_id)."
    ];
}

function send_lead_to_crm( $post_id ) {
    $author_id = get_post_field( 'post_author', $post_id );
    $author_name = get_the_author_meta( 'display_name', $author_id );

    $explode_author_name = explode( ' ', $author_name );
    $firstname = $explode_author_name[0];
    unset( $explode_author_name[0] );
    $lastname = implode( ' ', $explode_author_name );

    $post_meta = get_post_meta( $post_id );

    $name = $post_meta['nome_fantasia'][0] ?? '';
    $cnpj = $post_meta['cnpj'][0] ?? '';

    // Check required fields
    if ( $name && $cnpj ) {

        $systemuser = get_option( 'systemuser' );

        $entity_data = [
            'ownerid'                    => create_crm_reference( 'systemuser', $systemuser ),
            'address1_city'              => $post_meta['end_cidade'][0] ?? '',
            'address1_postalcode'        => $post_meta['end_cep'][0] ?? '',
            'companyname'                => $name,
            'entityimage_url'            => \get_the_post_thumbnail_url( $post_id ),
            'firstname'                  => $name,
            'fullname'                   => $name,
            'fut_address1_logradouro'    => $post_meta['end_logradouro'][0] ?? '',
            'fut_address1_nro'           => $post_meta['end_numero'][0] ?? '',
            'fut_st_cnpj'                => $cnpj,
            'fut_st_cnpj'                => $cnpj,
            'fut_st_complementoorigem'   => $post_meta['segmento'][0] ?? '',
            'fut_st_inscricaoestadual'   => $post_meta['inscricao_estadual'][0] ?? '',
            'fut_st_inscricaomunicipal'  => $post_meta['inscricao_municipal'][0] ?? '',
            'fut_st_nome'                => $firstname,
            'fut_st_nomecompleto'        => $author_name,
            'fut_st_nomefantasiaempresa' => $name,
            'fut_st_sobrenome'           => $lastname,
            'leadsourcecode'             => 4, // Outros
            'websiteurl'                 => $post_meta['website'][0] ?? '',
            'yomifirstname'              => $firstname,
            'yomifullname'               => $name,
            'yomilastname'               => $lastname
        ];

        $filtered_data = array_filter( $entity_data, 'hacklabr\\array_filter_args' );

        $entity = new \AlexaCRM\Xrm\Entity('lead');

        foreach ( $filtered_data as $key => $value ) {
            $entity[$key] = $value;
        }

        try {

            $client = get_client_on_dynamics();

            $entityId = $client->Create( $entity );
            return [
                'status'    => 'success',
                'message'   => 'Entidade criada com sucesso no CRM.',
                'entity_id' => $entityId
            ];

        } catch ( \AlexaCRM\WebAPI\ODataException $e ) {

            return [
                'status'  => 'error',
                'message' => "Ocorreu um erro ao criar a entidade no CRM (Post ID $post_id): " . $e->getMessage()
            ];

        } catch ( \AlexaCRM\WebAPI\EntityNotSupportedException $e ) {

            return [
                'status'  => 'error',
                'message' => "Entidade `{$entity->LogicalName}` não é suportada pelo CRM (Post ID $post_id)."
            ];

        } catch ( \AlexaCRM\WebAPI\TransportException $e ) {

            update_post_meta( $post_id, 'error_data', $entity );

            return [
                'status'  => 'error',
                'message' => "Erro de transporte ao comunicar com o CRM (Post ID $post_id): " . $e->getMessage()
            ];

        } catch ( \Exception $e ) {

            return [
                'status'  => 'error',
                'message' => "Erro inesperado (Post ID $post_id): " . $e->getMessage()
            ];

        }
    }

    update_meta_log_error( $post_id, 'Dados insuficientes para criar a entidade no CRM.' );
    return [
        'status'  => 'error',
        'message' => "Dados insuficientes para criar a entidade no CRM (Post ID $post_id)."
    ];
}

/**
 * Retorna uma instancia de `client` do Dynamics
 */
function get_client_on_dynamics() {

    try {
        $client = \AlexaCRM\WebAPI\ClientFactory::createOnlineClient(
            get_crm_server_url(),
            get_crm_application_id(),
            get_crm_client_secret()
        );

        return $client;
    } catch ( \Exception $e ) {
        do_action( 'logger', $e->getMessage() );
    }

    return false;

}

// salva um participante em um evento no CRM
function save_participant( $params ) {
    $contact_id = $params['contact_id'] ?? '';
    $project_id = $params['project_id'] ?? '';

    if ( empty( $contact_id ) || empty( $project_id ) ) {
        return [
            'status'  => 'error',
            'message' => "um ou mais parâmetro obrigatório não foi informado."
        ];
    }

    $entity_data = [
        'fut_lk_contato' => create_crm_reference( 'contact', $contact_id ),
        'fut_lk_projeto' => create_crm_reference( 'fut_projeto', $project_id )
    ];

    $entity = new \AlexaCRM\Xrm\Entity( 'fut_participante' );

    foreach ( $entity_data as $key => $value ) {
        $entity[$key] = $value;
    }

    try {

        $client = get_client_on_dynamics();

        $entityId = $client->Create( $entity );
        return [
            'status'    => 'success',
            'message'   => 'Entidade criada com sucesso no CRM.',
            'entity_id' => $entityId
        ];

    } catch ( \AlexaCRM\WebAPI\ODataException $e ) {

        return [
            'status'  => 'error',
            'message' => "Ocorreu um erro ao criar a entidade no CRM : " . $e->getMessage()
        ];

    } catch ( \AlexaCRM\WebAPI\EntityNotSupportedException $e ) {

        return [
            'status'  => 'error',
            'message' => "Entidade `{$entity->LogicalName}` não é suportada pelo CRM ."
        ];

    } catch ( \AlexaCRM\WebAPI\TransportException $e ) {

        return [
            'status'  => 'error',
            'message' => "Erro de transporte ao comunicar com o CRM : " . $e->getMessage()
        ];

    } catch ( \Exception $e ) {

        return [
            'status'  => 'error',
            'message' => "Erro inesperado : " . $e->getMessage()
        ];

    }
}

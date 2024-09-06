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
        $lead_response = send_lead_to_crm( $post_id );

        if ( $lead_response['status'] === 'success' ) {
            $lead_id = $lead_response['entity_id'];

            update_post_meta( $post_id, '_ethos_crm_lead_id', $lead_id );

            // apaga o erro de log do post
            \delete_post_meta( $post_id, 'log_error' );

            // remove post da lista de sincronização
            remove_from_sync_waiting_list( $post_id );

            // adiciona post na lista de aprovação
            add_to_approval_waiting( $post_id );
        } else {
            // salva o erro de log no data_logger
            do_action( 'logger', $lead_response['message'] );

            // salva o erro de log no post
            update_meta_log_error( $post_id, $lead_response['message'] );
        }
    }
}

add_action( 'sync_entity', 'hacklabr\sync_entity', 10 );

function approval_entity_contacts( $group_id, $account_id ) {
    $group = get_pmpro_group( intval( $group_id ) );

    $parent_user_id = $group->group_parent_user_id;
    $parent_level_id = $group->group_parent_level_id;

    $is_approved = \PMPro_Approvals::approveMember( $parent_user_id, $parent_level_id, true );
    if ( ! $is_approved ) {
        update_user_meta( $parent_user_id, 'log_error', [
            'message' => 'Erro ao aprovar usuário',
            'group' => $group_id,
        ] );
    }

    update_user_meta( $parent_user_id, '_ethos_crm_account_id', $account_id );

    \ethos\crm\update_contact( $parent_user_id );

    $child_members = $group->get_active_members(false);
    foreach ($child_members as $child_member) {
        $child_user_id = $child_member->group_child_user_id;
        $child_level_id = $child_member->group_child_level_id;

        update_user_meta( $child_user_id, '_ethos_crm_account_id', $account_id );

        $is_approved = \PMPro_Approvals::approveMember( $child_user_id, $child_level_id, true );
        if ( ! $is_approved ) {
            update_user_meta( $child_user_id, 'log_error', [
                'message' => 'Erro ao aprovar usuário',
                'group' => $group_id,
            ] );
        }
    }
}

function approval_entity( $post_id ) {
    $waiting_approval = get_option( '_ethos_waiting_approval', [] );

    if ( ( array_search( $post_id, $waiting_approval ) ) !== false ) {
        $lead_id = get_post_meta( $post_id, '_ethos_crm_lead_id', true );
        $lead = get_crm_entity_by_id( 'lead', $lead_id );

        if ( $lead && $lead->GetAttributeValue( 'parentaccountid' ) ) {
            $parent_account = $lead->GetAttributeValue( 'parentaccountid' );

            if ( $parent_account->Id ) {
                $account_id = $parent_account->Id;

                update_post_meta( $post_id, '_ethos_crm_account_id', $account_id );
                \ethos\crm\update_account( $post_id, $account_id );

                \delete_post_meta( $post_id, 'log_error' );

                remove_from_approval_waiting( $post_id );

                $group_id = get_post_meta( $post_id, '_pmpro_group', true );

                if ( ! empty( $group_id ) ) {
                    approval_entity_contacts( $group_id, $account_id );
                } else {
                    update_post_meta( $post_id, 'log_error', 'Erro no grupo' );
                }
            }
        }
    }
}

add_action( 'approval_entity', 'hacklabr\approval_entity', 10 );

function add_post_to_sync_waiting_list( $post_id ) {
    $group_id = get_post_meta( $post_id, '_pmpro_group', true );
    $lead_id = get_post_meta( $post_id, '_ethos_crm_lead_id', true );
    $is_imported = get_post_meta( $post_id, '_ethos_from_crm', true );

    if ( $group_id && ! $lead_id && ! $is_imported ) {
        add_to_sync_waiting_list( $post_id );
    }

}

add_action( 'save_post_organizacao', 'hacklabr\add_post_to_sync_waiting_list', 10, 1 );

function cancel_sync( $post_id ) {
    remove_from_sync_waiting_list( $post_id );
    remove_from_approval_waiting( $post_id );
}

function send_lead_to_crm( $post_id ) {
    $name = get_post_meta( $post_id, 'nome_fantasia', true );
    $cnpj = get_post_meta( $post_id, 'cnpj', true );

    // Check required fields
    if ( $name && $cnpj ) {
        $attributes = \ethos\crm\map_lead_attributes( $post_id );

        try {

            $entity_id = create_crm_entity( 'lead', $attributes );

            update_post_meta( $post_id, '_ethos_crm_lead_id', $entity_id );

            return [
                'status'    => 'success',
                'message'   => 'Entidade criada com sucesso no CRM.',
                'entity_id' => $entity_id,
            ];

        } catch ( \Exception $e ) {

            return [
                'status'  => 'error',
                'message' => "Erro ao criar lead (Post ID $post_id): " . $e->getMessage()
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

    $attributes = [
        'fut_lk_contato' => create_crm_reference( 'contact', $contact_id ),
        'fut_lk_projeto' => create_crm_reference( 'fut_projeto', $project_id )
    ];

    try {

        $entity_id = create_crm_entity( 'fut_participante', $attributes );

        return [
            'status'    => 'success',
            'message'   => 'Entidade criada com sucesso no CRM.',
            'entity_id' => $entity_id,
        ];

    } catch ( \Exception $e ) {

        return [
            'status'  => 'error',
            'message' => "Erro ao salvar participante: " . $e->getMessage()
        ];

    }
}

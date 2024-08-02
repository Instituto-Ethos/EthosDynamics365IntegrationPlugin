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
                    update_user_meta( $user_id, 'log_error', 'Erro no grupo' );
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
    $options = get_option( 'msdyncrm_options' );
    $serverUrl = $options['serverUrl'] ?? '';

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

            $client = \AlexaCRM\WebAPI\ClientFactory::createOnlineClient(
                $serverUrl,
                $options['applicationId'],
                $options['clientSecret']
            );

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
    $options = get_option( 'msdyncrm_options' );
    $serverUrl = $options['serverUrl'] ?? '';

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
            'ownerid'                    => new \AlexaCRM\Xrm\EntityReference( 'systemuser', $systemuser ),
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

            $client = \AlexaCRM\WebAPI\ClientFactory::createOnlineClient(
                $serverUrl,
                $options['applicationId'],
                $options['clientSecret']
            );

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

function send_contact_to_crm($args = array()) {
    /*
        Dica do AlexaCRM
        $contact = $service->entity( 'contact' );
        $contact->firstname = 'Maria';
        $contact->lastname = 'de Luz';
        $contact->emailaddress1 = 'maria.luz@testecrm.com';
        $contactId = $contact->create();
    */

    $options = \get_option( 'msdyncrm_options' );
    $serverUrl = $options['serverUrl'] ?? '';

    // Format: html request name OR args name => entity_data name
    $fields_list = array(
        'firstname'                 => 'firstname',
        'lastname'                  => 'lastname',
        'cpf'                       => 'fut_st_cpf',
        'email'                     => 'emailaddress1',
        'mobilephone'               => 'mobilephone',
        'telephone1'                => 'telephone1',
        'birthdate'                 => array('birthdate', 'date'),
        //address1
        'address1_city'             => 'address1_city',
        'address1_country'          => 'address1_country',
        'address1_line1'            => 'address1_line1',
        'address1_line2'            => 'address1_line2',
        'address1_line3'            => 'address1_line3',
        'fut_address1_logradouro'   => 'fut_address1_logradouro',
        'fut_address1_nro'          => 'fut_address1_nro',
        'address1_postalcode'       => 'address1_postalcode',
        'fut_pl_estado'             => array('fut_pl_estado', 'eval', "\hacklabr\get_state_info"),
        'fut_lk_tipologradouroname' => 'fut_lk_tipologradouroname',
        //END address1
        'jobtitle'                  => 'jobtitle',
        'fut_pl_nivelhierarquico'   => 'fut_pl_nivelhierarquico',
        'fut_pl_area'               => 'fut_pl_area',
    );

    $post_id = \get_the_ID();

    $systemuser = \get_option( 'systemuser' );

    $entity_data = [
        // Required
        //'firstname'                  => $firstname, //Tipo: StringType
        //'parentcustomeridname' => '', //Tipo: StringType - Possilvemente vem do CRM/API
        //'parentcustomeridyominame' => '', //Tipo: StringType - Possilvemente vem do CRM/API
        // Wanted
        //'lastname'                   => $lastname, //Tipo: StringType
        // Optional
        'ownerid'                    => new \AlexaCRM\Xrm\EntityReference( 'systemuser', $systemuser ),
        //'fut_st_cpf'                 => $cpf, //Tipo: StringType
        //'emailaddress1'              => $email, //Tipo: StringType
        /*'accountid' => '', //Tipo: LookupType
        'accountidname' => '', //Tipo: StringType
        'accountidyominame' => '', //Tipo: StringType
        'accountrolecode' => '', //Tipo: PicklistType
        'accountrolecodename' => '', //Tipo: VirtualType
        'address1_addressid' => '', //Tipo: UniqueidentifierType
        'address1_addresstypecode' => '', //Tipo: PicklistType
        'address1_addresstypecodename' => '', //Tipo: VirtualType
        'address1_city' => '', //Tipo: StringType
        'address1_composite' => '', //Tipo: MemoType
        'address1_country' => '', //Tipo: StringType
        'address1_county' => '', //Tipo: StringType
        'address1_fax' => '', //Tipo: StringType
        'address1_freighttermscode' => '', //Tipo: PicklistType
        'address1_freighttermscodename' => '', //Tipo: VirtualType
        'address1_latitude' => '', //Tipo: DoubleType
        'address1_line1' => '', //Tipo: StringType
        'address1_line2' => '', //Tipo: StringType
        'address1_line3' => '', //Tipo: StringType
        'address1_longitude' => '', //Tipo: DoubleType
        'address1_name' => '', //Tipo: StringType
        'address1_postalcode' => '', //Tipo: StringType
        'address1_postofficebox' => '', //Tipo: StringType
        'address1_primarycontactname' => '', //Tipo: StringType
        'address1_shippingmethodcode' => '', //Tipo: PicklistType
        'address1_shippingmethodcodename' => '', //Tipo: VirtualType
        'address1_stateorprovince' => '', //Tipo: StringType
        'address1_telephone1' => '', //Tipo: StringType
        'address1_telephone2' => '', //Tipo: StringType
        'address1_telephone3' => '', //Tipo: StringType
        'address1_upszone' => '', //Tipo: StringType
        'address1_utcoffset' => '', //Tipo: IntegerType
        'address2_addressid' => '', //Tipo: UniqueidentifierType
        'address2_addresstypecode' => '', //Tipo: PicklistType
        'address2_addresstypecodename' => '', //Tipo: VirtualType
        'address2_city' => '', //Tipo: StringType
        'address2_composite' => '', //Tipo: MemoType
        'address2_country' => '', //Tipo: StringType
        'address2_county' => '', //Tipo: StringType
        'address2_fax' => '', //Tipo: StringType
        'address2_freighttermscode' => '', //Tipo: PicklistType
        'address2_freighttermscodename' => '', //Tipo: VirtualType
        'address2_latitude' => '', //Tipo: DoubleType
        'address2_line1' => '', //Tipo: StringType
        'address2_line2' => '', //Tipo: StringType
        'address2_line3' => '', //Tipo: StringType
        'address2_longitude' => '', //Tipo: DoubleType
        'address2_name' => '', //Tipo: StringType
        'address2_postalcode' => '', //Tipo: StringType
        'address2_postofficebox' => '', //Tipo: StringType
        'address2_primarycontactname' => '', //Tipo: StringType
        'address2_shippingmethodcode' => '', //Tipo: PicklistType
        'address2_shippingmethodcodename' => '', //Tipo: VirtualType
        'address2_stateorprovince' => '', //Tipo: StringType
        'address2_telephone1' => '', //Tipo: StringType
        'address2_telephone2' => '', //Tipo: StringType
        'address2_telephone3' => '', //Tipo: StringType
        'address2_upszone' => '', //Tipo: StringType
        'address2_utcoffset' => '', //Tipo: IntegerType
        'address3_addressid' => '', //Tipo: UniqueidentifierType
        'address3_addresstypecode' => '', //Tipo: PicklistType
        'address3_addresstypecodename' => '', //Tipo: VirtualType
        'address3_city' => '', //Tipo: StringType
        'address3_composite' => '', //Tipo: MemoType
        'address3_country' => '', //Tipo: StringType
        'address3_county' => '', //Tipo: StringType
        'address3_fax' => '', //Tipo: StringType
        'address3_freighttermscode' => '', //Tipo: PicklistType
        'address3_freighttermscodename' => '', //Tipo: VirtualType
        'address3_latitude' => '', //Tipo: DoubleType
        'address3_line1' => '', //Tipo: StringType
        'address3_line2' => '', //Tipo: StringType
        'address3_line3' => '', //Tipo: StringType
        'address3_longitude' => '', //Tipo: DoubleType
        'address3_name' => '', //Tipo: StringType
        'address3_postalcode' => '', //Tipo: StringType
        'address3_postofficebox' => '', //Tipo: StringType
        'address3_primarycontactname' => '', //Tipo: StringType
        'address3_shippingmethodcode' => '', //Tipo: PicklistType
        'address3_shippingmethodcodename' => '', //Tipo: VirtualType
        'address3_stateorprovince' => '', //Tipo: StringType
        'address3_telephone1' => '', //Tipo: StringType
        'address3_telephone2' => '', //Tipo: StringType
        'address3_telephone3' => '', //Tipo: StringType
        'address3_upszone' => '', //Tipo: StringType
        'address3_utcoffset' => '', //Tipo: IntegerType
        'adx_confirmremovepassword' => '', //Tipo: BooleanType
        'adx_confirmremovepasswordname' => '', //Tipo: VirtualType
        'adx_createdbyipaddress' => '', //Tipo: StringType
        'adx_createdbyusername' => '', //Tipo: StringType
        'adx_identity_accessfailedcount' => '', //Tipo: IntegerType
        'adx_identity_emailaddress1confirmed' => '', //Tipo: BooleanType
        'adx_identity_emailaddress1confirmedname' => '', //Tipo: VirtualType
        'adx_identity_lastsuccessfullogin' => '', //Tipo: DateTimeType
        'adx_identity_locallogindisabled' => '', //Tipo: BooleanType
        'adx_identity_locallogindisabledname' => '', //Tipo: VirtualType
        'adx_identity_lockoutenabled' => '', //Tipo: BooleanType
        'adx_identity_lockoutenabledname' => '', //Tipo: VirtualType
        'adx_identity_lockoutenddate' => '', //Tipo: DateTimeType
        'adx_identity_logonenabled' => '', //Tipo: BooleanType
        'adx_identity_logonenabledname' => '', //Tipo: VirtualType
        'adx_identity_mobilephoneconfirmed' => '', //Tipo: BooleanType
        'adx_identity_mobilephoneconfirmedname' => '', //Tipo: VirtualType
        'adx_identity_newpassword' => '', //Tipo: StringType
        'adx_identity_passwordhash' => '', //Tipo: StringType
        'adx_identity_securitystamp' => '', //Tipo: StringType
        'adx_identity_twofactorenabled' => '', //Tipo: BooleanType
        'adx_identity_twofactorenabledname' => '', //Tipo: VirtualType
        'adx_identity_username' => '', //Tipo: StringType
        'adx_modifiedbyipaddress' => '', //Tipo: StringType
        'adx_modifiedbyusername' => '', //Tipo: StringType
        'adx_organizationname' => '', //Tipo: StringType
        'adx_portalinvitationcode' => '', //Tipo: StringType
        'adx_portalinvitationurl' => '', //Tipo: StringType
        'adx_preferredlanguageid' => '', //Tipo: LookupType
        'adx_preferredlanguageidname' => '', //Tipo: StringType
        'adx_preferredlcid' => '', //Tipo: IntegerType
        'adx_profilealert' => '', //Tipo: BooleanType
        'adx_profilealertdate' => '', //Tipo: DateTimeType
        'adx_profilealertinstructions' => '', //Tipo: MemoType
        'adx_profilealertname' => '', //Tipo: VirtualType
        'adx_profileisanonymous' => '', //Tipo: BooleanType
        'adx_profileisanonymousname' => '', //Tipo: VirtualType
        'adx_profilelastactivity' => '', //Tipo: DateTimeType
        'adx_profilemodifiedon' => '', //Tipo: DateTimeType
        'adx_publicprofilecopy' => '', //Tipo: MemoType
        'adx_timezone' => '', //Tipo: IntegerType
        'aging30' => '', //Tipo: MoneyType
        'aging30_base' => '', //Tipo: MoneyType
        'aging60' => '', //Tipo: MoneyType
        'aging60_base' => '', //Tipo: MoneyType
        'aging90' => '', //Tipo: MoneyType
        'aging90_base' => '', //Tipo: MoneyType
        'anniversary' => '', //Tipo: DateTimeType
        'annualincome' => '', //Tipo: MoneyType
        'annualincome_base' => '', //Tipo: MoneyType
        'assistantname' => '', //Tipo: StringType
        'assistantphone' => '', //Tipo: StringType
        'birthdate' => '', //Tipo: DateTimeType
        'business2' => '', //Tipo: StringType
        'businesscard' => '', //Tipo: MemoType
        'businesscardattributes' => '', //Tipo: StringType
        'callback' => '', //Tipo: StringType
        'childrensnames' => '', //Tipo: StringType
        'company' => '', //Tipo: StringType
        'contactid' => '', //Tipo: UniqueidentifierType
        'createdby' => '', //Tipo: LookupType
        'createdbyexternalparty' => '', //Tipo: LookupType
        'createdbyexternalpartyname' => '', //Tipo: StringType
        'createdbyexternalpartyyominame' => '', //Tipo: StringType
        'createdbyname' => '', //Tipo: StringType
        'createdbyyominame' => '', //Tipo: StringType
        'createdon' => '', //Tipo: DateTimeType
        'createdonbehalfby' => '', //Tipo: LookupType
        'createdonbehalfbyname' => '', //Tipo: StringType
        'createdonbehalfbyyominame' => '', //Tipo: StringType
        'creditlimit' => '', //Tipo: MoneyType
        'creditlimit_base' => '', //Tipo: MoneyType
        'creditonhold' => '', //Tipo: BooleanType
        'creditonholdname' => '', //Tipo: VirtualType
        'customersizecode' => '', //Tipo: PicklistType
        'customersizecodename' => '', //Tipo: VirtualType
        'customertypecode' => '', //Tipo: PicklistType
        'customertypecodename' => '', //Tipo: VirtualType
        'defaultpricelevelid' => '', //Tipo: LookupType
        'defaultpricelevelidname' => '', //Tipo: StringType
        'department' => '', //Tipo: StringType
        'description' => '', //Tipo: MemoType
        'donotbulkemail' => '', //Tipo: BooleanType
        'donotbulkemailname' => '', //Tipo: VirtualType
        'donotbulkpostalmail' => '', //Tipo: BooleanType
        'donotbulkpostalmailname' => '', //Tipo: VirtualType
        'donotemail' => '', //Tipo: BooleanType
        'donotemailname' => '', //Tipo: VirtualType
        'donotfax' => '', //Tipo: BooleanType
        'donotfaxname' => '', //Tipo: VirtualType
        'donotphone' => '', //Tipo: BooleanType
        'donotphonename' => '', //Tipo: VirtualType
        'donotpostalmail' => '', //Tipo: BooleanType
        'donotpostalmailname' => '', //Tipo: VirtualType
        'donotsendmarketingmaterialname' => '', //Tipo: VirtualType
        'donotsendmm' => '', //Tipo: BooleanType
        'educationcode' => '', //Tipo: PicklistType
        'educationcodename' => '', //Tipo: VirtualType
        'emailaddress2' => '', //Tipo: StringType
        'emailaddress3' => '', //Tipo: StringType
        'employeeid' => '', //Tipo: StringType
        'entityimage' => '', //Tipo: ImageType
        'entityimage_timestamp' => '', //Tipo: BigIntType
        'entityimage_url' => '', //Tipo: StringType
        'entityimageid' => '', //Tipo: UniqueidentifierType
        'exchangerate' => '', //Tipo: DecimalType
        'externaluseridentifier' => '', //Tipo: StringType
        'familystatuscode' => '', //Tipo: PicklistType
        'familystatuscodename' => '', //Tipo: VirtualType
        'fax' => '', //Tipo: StringType
        'followemail' => '', //Tipo: BooleanType
        'followemailname' => '', //Tipo: VirtualType
        'ftpsiteurl' => '', //Tipo: StringType
        'fullname' => '', //Tipo: StringType
        'fut_address1_logradouro' => '', //Tipo: StringType
        'fut_address1_nro' => '', //Tipo: StringType
        'fut_bit_enviaemail' => '', //Tipo: BooleanType
        'fut_bit_enviaemailname' => '', //Tipo: VirtualType
        'fut_bt_direitos_humanos' => '', //Tipo: BooleanType
        'fut_bt_direitos_humanosname' => '', //Tipo: VirtualType
        'fut_bt_eventos' => '', //Tipo: BooleanType
        'fut_bt_eventosname' => '', //Tipo: VirtualType
        'fut_bt_financeiro' => '', //Tipo: BooleanType
        'fut_bt_financeironame' => '', //Tipo: VirtualType
        'fut_bt_gestao_de_pessoas' => '', //Tipo: BooleanType
        'fut_bt_gestao_de_pessoasname' => '', //Tipo: VirtualType
        'fut_bt_indicadores' => '', //Tipo: BooleanType
        'fut_bt_indicadoresname' => '', //Tipo: VirtualType
        'fut_bt_integridade' => '', //Tipo: BooleanType
        'fut_bt_integridadename' => '', //Tipo: VirtualType
        'fut_bt_juridico' => '', //Tipo: BooleanType
        'fut_bt_juridiconame' => '', //Tipo: VirtualType
        'fut_bt_meio_ambiente' => '', //Tipo: BooleanType
        'fut_bt_meio_ambientename' => '', //Tipo: VirtualType
        'fut_bt_operacoes_e_suprimentos' => '', //Tipo: BooleanType
        'fut_bt_operacoes_e_suprimentosname' => '', //Tipo: VirtualType
        'fut_bt_palestrante' => '', //Tipo: BooleanType
        'fut_bt_palestrantename' => '', //Tipo: VirtualType
        'fut_bt_patrocinio' => '', //Tipo: BooleanType
        'fut_bt_patrocinioname' => '', //Tipo: VirtualType
        'fut_bt_presidente' => '', //Tipo: BooleanType
        'fut_bt_presidentename' => '', //Tipo: VirtualType
        'fut_bt_principal' => '', //Tipo: BooleanType
        'fut_bt_principalname' => '', //Tipo: VirtualType
        'fut_bt_relacoes_governamentais' => '', //Tipo: BooleanType
        'fut_bt_relacoes_governamentaisname' => '', //Tipo: VirtualType
        'fut_bt_relacoespublicasinternacionais' => '', //Tipo: BooleanType
        'fut_bt_relacoespublicasinternacionaisname' => '', //Tipo: VirtualType
        'fut_bt_secretaria' => '', //Tipo: BooleanType
        'fut_bt_secretarianame' => '', //Tipo: VirtualType
        'fut_bt_sustentabilidade' => '', //Tipo: BooleanType
        'fut_bt_sustentabilidadename' => '', //Tipo: VirtualType
        'fut_dt_ultimo_acesso' => '', //Tipo: DateTimeType
        'fut_lk_assistente' => '', //Tipo: LookupType
        'fut_lk_assistentename' => '', //Tipo: StringType
        'fut_lk_assistenteyominame' => '', //Tipo: StringType
        'fut_lk_cep' => '', //Tipo: LookupType
        'fut_lk_cepname' => '', //Tipo: StringType
        'fut_lk_contato_1' => '', //Tipo: LookupType
        'fut_lk_contato_1name' => '', //Tipo: StringType
        'fut_lk_contato_1yominame' => '', //Tipo: StringType
        'fut_lk_contato_2' => '', //Tipo: LookupType
        'fut_lk_contato_2name' => '', //Tipo: StringType
        'fut_lk_contato_2yominame' => '', //Tipo: StringType
        'fut_lk_contato_3' => '', //Tipo: LookupType
        'fut_lk_contato_3name' => '', //Tipo: StringType
        'fut_lk_contato_3yominame' => '', //Tipo: StringType
        'fut_lk_curriculo' => '', //Tipo: LookupType
        'fut_lk_curriculoname' => '', //Tipo: StringType
        'fut_lk_tipologradouro' => '', //Tipo: LookupType
        'fut_lk_tipologradouroname' => '', //Tipo: StringType
        'fut_pl_acesso_area_associado' => '', //Tipo: PicklistType
        'fut_pl_acesso_area_associadoname' => '', //Tipo: VirtualType
        'fut_pl_area' => '', //Tipo: PicklistType
        'fut_pl_areaname' => '', //Tipo: VirtualType
        'fut_pl_estado' => '', //Tipo: PicklistType
        'fut_pl_estadoname' => '', //Tipo: VirtualType
        'fut_pl_nivelhierarquico' => '', //Tipo: PicklistType
        'fut_pl_nivelhierarquiconame' => '', //Tipo: VirtualType
        'fut_pl_tipologradouro' => '', //Tipo: PicklistType
        'fut_pl_tipologradouroname' => '', //Tipo: VirtualType
        'fut_st_cpf' => '', //Tipo: StringType
        'fut_st_enderecoskype' => '', //Tipo: StringType
        'fut_st_idcontato' => '', //Tipo: StringType
        'fut_st_inscricaoestadual' => '', //Tipo: StringType
        'fut_st_inscricaomunicipal' => '', //Tipo: StringType
        'fut_st_password' => '', //Tipo: StringType
        'fut_st_ramal' => '', //Tipo: StringType
        'fut_st_token' => '', //Tipo: StringType
        'gendercode' => '', //Tipo: PicklistType
        'gendercodename' => '', //Tipo: VirtualType
        'governmentid' => '', //Tipo: StringType
        'haschildrencode' => '', //Tipo: PicklistType
        'haschildrencodename' => '', //Tipo: VirtualType
        'home2' => '', //Tipo: StringType
        'i4d_conferencia_ethos' => '', //Tipo: BooleanType
        'i4d_conferencia_ethosname' => '', //Tipo: VirtualType
        'i4d_consultor' => '', //Tipo: BooleanType
        'i4d_consultorname' => '', //Tipo: VirtualType
        'i4d_facebook' => '', //Tipo: StringType
        'i4d_linkedin' => '', //Tipo: StringType
        'i4d_nome_arquivo_contato' => '', //Tipo: StringType
        'i4d_proprietario_conta_email' => '', //Tipo: StringType
        'i4d_proprietario_conta_nome' => '', //Tipo: StringType
        'i4d_rg' => '', //Tipo: StringType
        'i4d_sexo' => '', //Tipo: PicklistType
        'i4d_sexoname' => '', //Tipo: VirtualType
        'i4d_site_consultoria' => '', //Tipo: StringType
        'i4d_url_arquivo_contato' => '', //Tipo: StringType
        'importsequencenumber' => '', //Tipo: IntegerType
        'isautocreate' => '', //Tipo: BooleanType
        'isbackofficecustomer' => '', //Tipo: BooleanType
        'isbackofficecustomername' => '', //Tipo: VirtualType
        'isprivate' => '', //Tipo: BooleanType
        'isprivatename' => '', //Tipo: VirtualType
        'jobtitle' => '', //Tipo: StringType
        'lastonholdtime' => '', //Tipo: DateTimeType
        'lastusedincampaign' => '', //Tipo: DateTimeType
        'leadsourcecode' => '', //Tipo: PicklistType
        'leadsourcecodename' => '', //Tipo: VirtualType
        'managername' => '', //Tipo: StringType
        'managerphone' => '', //Tipo: StringType
        'marketingonly' => '', //Tipo: BooleanType
        'marketingonlyname' => '', //Tipo: VirtualType
        'mastercontactidname' => '', //Tipo: StringType
        'mastercontactidyominame' => '', //Tipo: StringType
        'masterid' => '', //Tipo: LookupType
        'merged' => '', //Tipo: BooleanType
        'mergedname' => '', //Tipo: VirtualType
        'middlename' => '', //Tipo: StringType
        'mobilephone' => '', //Tipo: StringType
        'modifiedby' => '', //Tipo: LookupType
        'modifiedbyexternalparty' => '', //Tipo: LookupType
        'modifiedbyexternalpartyname' => '', //Tipo: StringType
        'modifiedbyexternalpartyyominame' => '', //Tipo: StringType
        'modifiedbyname' => '', //Tipo: StringType
        'modifiedbyyominame' => '', //Tipo: StringType
        'modifiedon' => '', //Tipo: DateTimeType
        'modifiedonbehalfby' => '', //Tipo: LookupType
        'modifiedonbehalfbyname' => '', //Tipo: StringType
        'modifiedonbehalfbyyominame' => '', //Tipo: StringType
        'msa_managingpartnerid' => '', //Tipo: LookupType
        'msa_managingpartneridname' => '', //Tipo: StringType
        'msa_managingpartneridyominame' => '', //Tipo: StringType
        'msdyn_contactkpiid' => '', //Tipo: LookupType
        'msdyn_contactkpiidname' => '', //Tipo: StringType
        'msdyn_decisioninfluencetag' => '', //Tipo: PicklistType
        'msdyn_decisioninfluencetagname' => '', //Tipo: VirtualType
        'msdyn_disablewebtracking' => '', //Tipo: BooleanType
        'msdyn_disablewebtrackingname' => '', //Tipo: VirtualType
        'msdyn_gdproptout' => '', //Tipo: BooleanType
        'msdyn_gdproptoutname' => '', //Tipo: VirtualType
        'msdyn_isassistantinorgchart' => '', //Tipo: BooleanType
        'msdyn_isassistantinorgchartname' => '', //Tipo: VirtualType
        'msdyn_isminor' => '', //Tipo: BooleanType
        'msdyn_isminorname' => '', //Tipo: VirtualType
        'msdyn_isminorwithparentalconsent' => '', //Tipo: BooleanType
        'msdyn_isminorwithparentalconsentname' => '', //Tipo: VirtualType
        'msdyn_orgchangestatus' => '', //Tipo: PicklistType
        'msdyn_orgchangestatusname' => '', //Tipo: VirtualType
        'msdyn_portaltermsagreementdate' => '', //Tipo: DateTimeType
        'msdyn_primarytimezone' => '', //Tipo: IntegerType
        'msdyn_segmentid' => '', //Tipo: LookupType
        'msdyn_segmentidname' => '', //Tipo: StringType
        'msdyncrm_contactid' => '', //Tipo: LookupType
        'msdyncrm_contactidname' => '', //Tipo: StringType
        'msdyncrm_customerjourneyid' => '', //Tipo: LookupType
        'msdyncrm_customerjourneyidname' => '', //Tipo: StringType
        'msdyncrm_emailid' => '', //Tipo: LookupType
        'msdyncrm_emailidname' => '', //Tipo: StringType
        'msdyncrm_insights_placeholder' => '', //Tipo: StringType
        'msdyncrm_marketingformid' => '', //Tipo: LookupType
        'msdyncrm_marketingformidname' => '', //Tipo: StringType
        'msdyncrm_marketingformsubmissiondateprecise' => '', //Tipo: StringType
        'msdyncrm_marketingpageid' => '', //Tipo: LookupType
        'msdyncrm_marketingpageidname' => '', //Tipo: StringType
        'msdyncrm_rememberme' => '', //Tipo: BooleanType
        'msdyncrm_remembermename' => '', //Tipo: VirtualType
        'msdyncrm_segmentmemberid' => '', //Tipo: LookupType
        'msdyncrm_segmentmemberidname' => '', //Tipo: StringType
        'msdynmkt_customerjourneyid' => '', //Tipo: LookupType
        'msdynmkt_customerjourneyidname' => '', //Tipo: StringType
        'msdynmkt_emailid' => '', //Tipo: LookupType
        'msdynmkt_emailidname' => '', //Tipo: StringType
        'msdynmkt_marketingformid' => '', //Tipo: LookupType
        'msdynmkt_marketingformidname' => '', //Tipo: StringType
        'msevtmgt_aadobjectid' => '', //Tipo: StringType
        'msevtmgt_contactid' => '', //Tipo: LookupType
        'msevtmgt_contactidname' => '', //Tipo: StringType
        'msevtmgt_originatingeventid' => '', //Tipo: LookupType
        'msevtmgt_originatingeventidname' => '', //Tipo: StringType
        'msgdpr_consentchangesourceformid' => '', //Tipo: LookupType
        'msgdpr_consentchangesourceformidname' => '', //Tipo: StringType
        'msgdpr_donottrack' => '', //Tipo: BooleanType
        'msgdpr_donottrackname' => '', //Tipo: VirtualType
        'msgdpr_gdprconsent' => '', //Tipo: PicklistType
        'msgdpr_gdprconsentname' => '', //Tipo: VirtualType
        'msgdpr_gdprischild' => '', //Tipo: BooleanType
        'msgdpr_gdprischildname' => '', //Tipo: VirtualType
        'msgdpr_gdprparentid' => '', //Tipo: LookupType
        'msgdpr_gdprparentidname' => '', //Tipo: StringType
        'msgdpr_gdprparentidyominame' => '', //Tipo: StringType
        'mspp_userpreferredlcid' => '', //Tipo: PicklistType
        'mspp_userpreferredlcidname' => '', //Tipo: VirtualType
        'nickname' => '', //Tipo: StringType
        'numberofchildren' => '', //Tipo: IntegerType
        'onholdtime' => '', //Tipo: IntegerType
        'originatingleadid' => '', //Tipo: LookupType
        'originatingleadidname' => '', //Tipo: StringType
        'originatingleadidyominame' => '', //Tipo: StringType
        'overriddencreatedon' => '', //Tipo: DateTimeType
        'ownerid' => '', //Tipo: OwnerType
        'owneridname' => '', //Tipo: StringType
        'owneridtype' => '', //Tipo: EntityNameType
        'owneridyominame' => '', //Tipo: StringType
        'owningbusinessunit' => '', //Tipo: LookupType
        'owningbusinessunitname' => '', //Tipo: StringType
        'owningteam' => '', //Tipo: LookupType
        'owninguser' => '', //Tipo: LookupType
        'pager' => '', //Tipo: StringType
        'parent_contactid' => '', //Tipo: LookupType
        'parent_contactidname' => '', //Tipo: StringType
        'parent_contactidyominame' => '', //Tipo: StringType
        'parentcontactid' => '', //Tipo: LookupType
        'parentcontactidname' => '', //Tipo: StringType
        'parentcontactidyominame' => '', //Tipo: StringType
        'parentcustomeridtype' => '', //Tipo: EntityNameType
        'participatesinworkflow' => '', //Tipo: BooleanType
        'participatesinworkflowname' => '', //Tipo: VirtualType
        'paymenttermscode' => '', //Tipo: PicklistType
        'paymenttermscodename' => '', //Tipo: VirtualType
        'preferredappointmentdaycode' => '', //Tipo: PicklistType
        'preferredappointmentdaycodename' => '', //Tipo: VirtualType
        'preferredappointmenttimecode' => '', //Tipo: PicklistType
        'preferredappointmenttimecodename' => '', //Tipo: VirtualType
        'preferredcontactmethodcode' => '', //Tipo: PicklistType
        'preferredcontactmethodcodename' => '', //Tipo: VirtualType
        'preferredequipmentid' => '', //Tipo: LookupType
        'preferredequipmentidname' => '', //Tipo: StringType
        'preferredserviceid' => '', //Tipo: LookupType
        'preferredserviceidname' => '', //Tipo: StringType
        'preferredsystemuserid' => '', //Tipo: LookupType
        'preferredsystemuseridname' => '', //Tipo: StringType
        'preferredsystemuseridyominame' => '', //Tipo: StringType
        'processid' => '', //Tipo: UniqueidentifierType
        'salutation' => '', //Tipo: StringType
        'shippingmethodcode' => '', //Tipo: PicklistType
        'shippingmethodcodename' => '', //Tipo: VirtualType
        'slaid' => '', //Tipo: LookupType
        'slainvokedid' => '', //Tipo: LookupType
        'slainvokedidname' => '', //Tipo: StringType
        'slaname' => '', //Tipo: StringType
        'spousesname' => '', //Tipo: StringType
        'stageid' => '', //Tipo: UniqueidentifierType
        'statecode' => '', //Tipo: StateType
        'statecodename' => '', //Tipo: VirtualType
        'statuscode' => '', //Tipo: StatusType
        'statuscodename' => '', //Tipo: VirtualType
        'subscriptionid' => '', //Tipo: UniqueidentifierType
        'suffix' => '', //Tipo: StringType
        'teamsfollowed' => '', //Tipo: IntegerType
        'telephone1' => '', //Tipo: StringType
        'telephone2' => '', //Tipo: StringType
        'telephone3' => '', //Tipo: StringType
        'territorycode' => '', //Tipo: PicklistType
        'territorycodename' => '', //Tipo: VirtualType
        'timespentbymeonemailandmeetings' => '', //Tipo: StringType
        'timezoneruleversionnumber' => '', //Tipo: IntegerType
        'transactioncurrencyid' => '', //Tipo: LookupType
        'transactioncurrencyidname' => '', //Tipo: StringType
        'traversedpath' => '', //Tipo: StringType
        'utcconversiontimezonecode' => '', //Tipo: IntegerType
        'versionnumber' => '', //Tipo: BigIntType
        'websiteurl' => '', //Tipo: StringType
        'yomifirstname' => '', //Tipo: StringType
        'yomifullname' => '', //Tipo: StringType
        'yomilastname' => '', //Tipo: StringType
        'yomimiddlename' => '', //Tipo: StringType*/
    ];

    foreach($fields_list as $field => $entity_field) {
        $data = \sanitize_text_field(isset($args[$field]) ? $args[$field] : (isset($_REQUEST[$field]) ? $_REQUEST[$field] : "" ) );
        if( !empty($data) ) {
            if( \is_array($entity_field) ) {
                switch($entity_field[1]) {
                    case 'date':
                        $date_field = \DateTime::createFromFormat('d/m/Y', $data );
                        $entity_data[$entity_field[0]] = $date_field->format('Y-m-d');
                    break;
                    case 'eval':
                        $field_data = $entity_field[2]($data);
                        $entity_data[$entity_field[0]] = trim($field_data);
                    break;
                    default:
                        $entity_data[$entity_field[0]] = trim($data);
                }
            } else {
                $entity_data[$entity_field] = trim($data);
            }
        }
    }

    // Check required fields
    if ( isset($entity_data['firstname']) && isset($entity_data['fut_st_cpf']) ) {

        $filtered_data = array_filter( $entity_data, 'hacklabr\\array_filter_args' );

        $entity = new \AlexaCRM\Xrm\Entity('contact');

        foreach ( $filtered_data as $key => $value ) {
            $entity[$key] = $value;
        }

        try {

            $client = \AlexaCRM\WebAPI\ClientFactory::createOnlineClient(
                $serverUrl,
                $options['applicationId'],
                $options['clientSecret']
            );

            $entityId = $client->Create( $entity );
            return [
                'status'    => 'success',
                'message'   => 'Entidade Contato criada com sucesso no CRM.',
                'entity_id' => $entityId
            ];

        } catch ( \AlexaCRM\WebAPI\ODataException $e ) {

            return [
                'status'  => 'error',
                'message' => "Ocorreu um erro ao criar a entidade Contato no CRM: " . $e->getMessage()
            ];

        } catch ( \AlexaCRM\WebAPI\EntityNotSupportedException $e ) {

            return [
                'status'  => 'error',
                'message' => "Entidade Contato `{$entity->LogicalName}` não é suportada pelo CRM."
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

    $options = get_option( 'msdyncrm_options' );
    $serverUrl = $options['serverUrl'] ?? '';

    $contact_id = $params['contact_id'] ?? '';
    $project_id = $params['project_id'] ?? '';

    if ( empty( $contact_id ) || empty( $project_id ) ) {
        return [
            'status'  => 'error',
            'message' => "um ou mais parâmetro obrigatório não foi informado."
        ];
    }

    $entity_data = [
        'fut_lk_contato' => new \AlexaCRM\Xrm\EntityReference( 'contact', $contact_id ),
        'fut_lk_projeto' => new \AlexaCRM\Xrm\EntityReference( 'fut_projeto', $project_id )
    ];

    $entity = new \AlexaCRM\Xrm\Entity( 'fut_participante' );

    foreach ( $entity_data as $key => $value ) {
        $entity[$key] = $value;
    }

    try {

        $client = \AlexaCRM\WebAPI\ClientFactory::createOnlineClient(
            $serverUrl,
            $options['applicationId'],
            $options['clientSecret']
        );

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

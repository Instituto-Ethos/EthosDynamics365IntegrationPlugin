<?php

namespace hacklabr;

function check_plugin_dependencies() {
    $missing_dependencies = [];

    $required_classes = [
        '\AlexaCRM\WebAPI\ClientFactory',
        '\AlexaCRM\Xrm\ColumnSet',
        '\AlexaCRM\Xrm\Entity',
        '\AlexaCRM\Xrm\Query\OrderType'
    ];

    foreach ( $required_classes as $class ) {
        if ( ! class_exists( $class ) ) {
            $missing_dependencies[] = $class;
        }
    }

    if ( ! empty( $missing_dependencies ) ) {
        add_action( 'admin_notices', function() use ( $missing_dependencies ) {
            ?>
            <div class="notice notice-error">
                <p><?php _e( 'The following required classes for the Ethos Dynamics 365 Integration plugin are missing:', 'hacklabr' ); ?></p>
                <ul>
                    <?php foreach ( $missing_dependencies as $class ) : ?>
                        <li><?php echo esc_html( $class ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php
        });

        return false;
    }

    return true;
}

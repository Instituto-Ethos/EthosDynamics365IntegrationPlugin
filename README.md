# Ethos Dynamics 365 Integration

## Installation

### Requirements

- Composer (https://getcomposer.org/)

### Installation Steps

1. Clone the repository:

    ```bash
    git clone git@github.com:Ethos-ti/EthosDynamics365IntegrationPlugin.git
    ```

2. Navigate to the theme directory:

    ```bash
    cd EthosDynamics365IntegrationPlugin/ethos-dynamics-365-integration
    ```

3. Install Composer dependencies:

    ```bash
    composer install
    ```

### Project Structure

- `/ethos-dynamics-365-integration` - Main directory of the plugin.
- `/dev-scripts` - Development scripts, such as `zip.sh`.

### Zip Script

To create a zip file of the plugin, use the `zip.sh` script:

1. Run the script `zip.sh`:

    ```bash
    ./dev-scripts/zip.sh
    ```

The zip file will be generated in the project root with the name `ethos-dynamics-365-integration.zip`.

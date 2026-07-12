# ScoutMagic

Open-source PHP website for Belgian scout units (Les Scouts federation). This project provides a reusable, configurable codebase that any scout unit can deploy on shared hosting with a MySQL database. All unit-specific data is configurable — nothing is hardcoded.

## Running locally

1. Install dependencies:
   ```bash
   composer install
   ```

2. Copy the configuration template:
   ```bash
   cp config/app.php.dist config/app.php
   ```

3. Start the development server:
   ```bash
   php -S localhost:8000 -t public
   ```

4. Open http://localhost:8000 in your browser.

## Architecture

See [ARCHITECTURE.md](ARCHITECTURE.md) for the full architectural reference.

## Security

See [SECURITY.md](SECURITY.md) for security requirements.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidelines.

## License

This project is licensed under the **AGPL-3.0** license. It is intended for use in open-source contexts only. Any deployment or modification of this software must comply with the AGPL-3.0 terms, including making source code available to users who interact with the software over a network.

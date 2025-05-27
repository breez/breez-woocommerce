# Lightning Payments for WooCommerce
Lightning Payments for WooCommerce is a simple Woocommerce extension that enables Lightning network payments for WooCommerce stores using the Breez Nodeless SDK via [payments-rest-api](https://github.com/breez/payments-rest-api).

## Features
- Accept Lightning network payments
- Seamless integration with WooCommerce checkout
- Easy configuration from the WooCommerce admin panel

## Installation
1. Download or clone this repository to your local machine.
2. Copy the `breez-woocommerce` folder to your WordPress `wp-content/plugins/` directory.
3. In your WordPress admin dashboard, go to **Plugins** and activate **Breez WooCommerce**.
4. Go to **WooCommerce > Settings > Payments** and enable the Breez payment gateway.
5. Enter your Breez Nodeless Payments API credentials and configure the gateway as needed.

## Requirements
- WordPress 5.0 or higher
- WooCommerce 4.0 or higher
- PHP 7.2 or higher

## Building and Deploying the Payments REST API to Fly.io

The WooCommerce plugin requires a separate REST API service to handle Lightning payments. This service is built using FastAPI and the Breez Nodeless SDK.

### Prerequisites

Before deploying to Fly.io, ensure you have:

- **Fly CLI**: Install from [fly.io](https://fly.io/docs/getting-started/installing-flyctl/)
- **Breez Nodeless SDK API Key**: Get one from [Breez Technology](https://breez.technology/)
- **Valid Seed Phrase**: For the Breez SDK wallet
- **Python 3.10+** (for local development/testing)
- **Poetry** (Python package manager)

### Installation of Fly CLI

```bash
# macOS
brew install flyctl

# Linux
curl -L https://fly.io/install.sh | sh

# Windows
pwsh -Command "iwr https://fly.io/install.ps1 -useb | iex"
```

### Build Process

The API service uses a Docker-based build process with the following stack:

- **Base Image**: Python 3.12
- **Framework**: FastAPI with Uvicorn
- **Dependencies**: Poetry for dependency management
- **SDK**: Breez Nodeless SDK for Lightning payments

The build process is handled automatically by Fly.io using the `Dockerfile` located in `payments-rest-api/fly/`.

### Deployment Steps

1. **Log in to Fly.io**:
   ```bash
   fly auth login
   ```

2. **Navigate to the API directory**:
   ```bash
   cd payments-rest-api/fly
   ```

3. **Launch the application**:
   ```bash
   fly launch
   ```
   
   This will:
   - Create a new Fly.io app
   - Generate a `fly.toml` configuration file
   - Set up initial deployment configuration

4. **Configure environment variables** (secrets):
   ```bash
   # Required: API secret for authentication
   fly secrets set API_SECRET=your_secure_api_secret_here
   
   # Required: Breez SDK API key
   fly secrets set BREEZ_API_KEY=your_breez_api_key
   
   # Required: Wallet seed phrase (12-24 words)
   fly secrets set SEED_PHRASE="your twelve word seed phrase here"
   
   # Optional: Webhook URL for WooCommerce notifications
   fly secrets set WEBHOOK_URL=https://your-woocommerce-site.com
   ```

5. **Deploy the application**:
   ```bash
   fly deploy
   ```

6. **Verify deployment**:
   ```bash
   # Check app status
   fly status
   
   # View logs
   fly logs
   
   # Test health endpoint
   curl https://your-app-name.fly.dev/health
   ```

### Configuration

After deployment, you'll need to configure your WooCommerce plugin with:

- **API URL**: `https://your-app-name.fly.dev`
- **API Secret**: The same `API_SECRET` you set during deployment
- **Webhook URL**: Your WooCommerce site URL (for payment notifications)

### Environment Variables

The API service requires the following environment variables:

| Variable | Required | Description |
|----------|----------|-------------|
| `API_SECRET` | Yes | Secret key for API authentication |
| `BREEZ_API_KEY` | Yes | Breez Nodeless SDK API key |
| `SEED_PHRASE` | Yes | 12-24 word seed phrase for wallet |
| `WEBHOOK_URL` | No | WooCommerce site URL for payment webhooks |
| `PORT` | No | API port (default: 8000) |

### Scaling and Monitoring

The default configuration includes:

- **Memory**: 1GB RAM
- **CPU**: 1 shared CPU
- **Auto-scaling**: Enabled (min 1 machine)
- **Storage**: Persistent volume for Breez SDK data

To scale your application:

```bash
# Scale to multiple machines
fly scale count 2

# Increase memory
fly scale memory 2048

# View scaling options
fly scale show
```

### Monitoring and Logs

Monitor your deployment:

```bash
# Real-time logs
fly logs -f

# Application metrics
fly dashboard

# Machine status
fly status
```

### Troubleshooting

Common issues and solutions:

1. **Build failures**: Check that all required files are present in `payments-rest-api/fly/`
2. **Runtime errors**: Verify environment variables are set correctly with `fly secrets list`
3. **Sync issues**: Monitor logs for Breez SDK sync status
4. **Webhook failures**: Ensure `WEBHOOK_URL` points to your WooCommerce site

### Testing the API

After deployment, test the API endpoints:

```bash
# Health check
curl https://your-app-name.fly.dev/health

# List payments (requires API_SECRET)
curl -X POST "https://your-app-name.fly.dev/list_payments" \
  -H "Content-Type: application/json" \
  -H "x-api-key: your_api_secret" \
  -d '{}'
```

For more detailed testing, see the `example_client.py` file in the `payments-rest-api/fly/` directory.

## Support
For support, please open an issue in this repository or contact the plugin maintainer.
# Devexa Core — License & Platform Configuration

## Overview
Central module for all Devexa extensions. Manages the license key, platform connection, and service activation. All other Devexa modules depend on this.

## What It Does
- **License validation** — Verifies your API key against the Devexa Platform (ai.devexa.ro)
- **Service gating** — Modules check `LicenseValidator::isServiceActive()` before rendering
- **Centralized config** — Single API key and platform URL for all modules
- **Domain binding** — License is tied to your registered domain(s)
- **Cache** — License is cached for 6 hours to avoid hitting the API on every request

## Configuration
**Stores > Config > Devexa > License & Platform**

| Field | Description |
|-------|-------------|
| License Key | Your `dxk_...` API key from ai.devexa.ro |
| License Status | Shows valid/invalid, active services, plan |
| Enable Dev Mode | Use local platform URL for development |
| Local Platform URL | e.g. `https://host.docker.internal:3443` |

## How It Works
1. Module calls `PlatformConfig::getApiKey()` — returns the decrypted license key
2. Module calls `LicenseValidator::isServiceActive('service_name')` — checks if the service is licensed
3. LicenseValidator calls `POST ai.devexa.ro/v1/license/validate` with the API key + domain
4. Platform returns `{valid: true, services: ['recommendations', ...]}`
5. Result is cached for 6 hours
6. If the platform is unreachable, the last cached result is used (graceful degradation)

## Key Classes
- `Devexa\Core\Model\PlatformConfig` — API key, platform URL, SSL settings
- `Devexa\Core\Model\LicenseValidator` — License validation + service checks
- `Devexa\Core\Block\Adminhtml\System\Config\LicenseStatus` — Admin status display

## Requirements
- Magento 2.4+
- PHP 8.1+
- Active Devexa Platform account (ai.devexa.ro)

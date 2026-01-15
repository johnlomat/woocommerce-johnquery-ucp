# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.5.0] - 2025-01-15

### Changed
- Renamed plugin to WooCommerce JohnQuery UCP
- Updated vendor prefix to `wc_jq_ucp_` for uniqueness
- REST API namespace changed to `wc-jq-ucp/v1`
- Database table renamed to `wc_jq_ucp_sessions`
- Author updated to JohnQuery

## [1.4.0] - 2025-01-15

### Added
- UCP session loading on WooCommerce checkout page
- Pre-fill customer information from UCP session
- Pre-fill shipping and billing addresses
- Link WooCommerce orders to UCP sessions
- Success message when cart is loaded from UCP session

## [1.3.0] - 2025-01-15

### Fixed
- WooCommerce shipping calculation in REST API context
- Initialize WC session and customer objects before shipping calculation

## [1.2.0] - 2025-01-15

### Added
- Uninstall handler for complete cleanup on plugin deletion
- Version number in plugin header
- Author information (John Lomat / JohnQuery)

### Changed
- Plugin URI updated to johnquery.com

## [1.1.0] - 2025-01-15

### Added
- Discovery endpoint status indicator in admin
- "Create Endpoint File" button in settings
- View Discovery Profile button
- View REST API Profile button

## [1.0.0] - 2025-01-15

### Added
- Initial release
- UCP discovery endpoint at `/.well-known/ucp`
- REST API endpoints for checkout sessions
- Create, read, update, cancel checkout sessions
- Complete checkout with embedded checkout flow
- Shipping calculation based on customer address
- Support for Bank Transfer and Cash on Delivery payments
- Admin settings page under WooCommerce menu
- Session timeout configuration
- Agent whitelist functionality
- Debug mode for logging
- Database table for storing checkout sessions

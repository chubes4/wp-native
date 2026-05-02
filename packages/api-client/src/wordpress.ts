/**
 * WordPress entry point — only import from inside Gutenberg blocks.
 *
 * Keeps @wordpress/api-fetch out of the main bundle so React Native /
 * Node consumers don't pull in WP-specific deps.
 */

export { WpApiFetchTransport } from './transports/wp-api-fetch';

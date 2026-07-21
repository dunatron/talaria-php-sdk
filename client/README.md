# Browser SDK (npm)

Silverstripe loads the published package **`@newtalaria/browser`** from the npm CDN (jsDelivr), not from a local monorepo checkout.

- Package: https://www.npmjs.com/package/@newtalaria/browser
- URL pattern: `https://cdn.jsdelivr.net/npm/@newtalaria/browser@{version}/+esm`
- Version pin: `Talaria\SilverStripe\Config.browserSdkVersion` (default `0.1.6`)

CMS injects `inlineStylesheet: true` (auth-gated admin CSS) and `failedRequestStatusCodes: [[400, 599]]`. Frontend defaults to `inlineStylesheet: false` and `[[500, 599]]`. Override with `browserInlineStylesheet`, `browserCaptureFailedRequests`, and `browserFailedRequestStatusCodes` in YAML.

No vendored JS bundle is required in this Composer package. After publishing a new npm release, bump `browserSdkVersion` and flush.

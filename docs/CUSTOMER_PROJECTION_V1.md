# Customer Projection V1 (staging only)

`/api/customer-projection/v1` is a dedicated, read-only SAVERPOS API for the SaverBro web connector. It is not a staff route, generic query endpoint or commerce command surface.

The endpoint is enabled only when the Laravel environment is `staging`, `RECOMMERCE_CUSTOMER_PROJECTION_ENABLED=true`, a separate high-entropy `RECOMMERCE_CUSTOMER_PROJECTION_BEARER_TOKEN` is present, and the configured business/location/variation cohort is non-empty. Requests require that token as a Bearer credential. Disabled or production configurations return a neutral unavailable response.

No token, internal Device ID, serial/IMEI, supplier, acquisition cost, margin, technician note, customer, audit event, custody history or internal product/variation ID is serialized.

An exact Device is exposed only when it is both operationally sellable (`AVAILABLE`, `LOCATION` custody, `ON_HAND`, no transfer, no sale, correct business/branch/variation) and explicitly merchandised (`PUBLISHED`, positive exact listing price, public Device ID, model slug and public specification ID).

The V1 Passport exposes actual inspection presence only. It does not reuse the post-sale certification record and reports unrecorded condition, battery, defects, refurbishment and warranty facts as unavailable rather than fabricating them.

## Discovery contract

`GET /api/customer-projection/v1/listings` is the source-backed exact-device discovery endpoint. It accepts only customer-safe filters: `category`, `brand`, `model_slug`, `cpu`, `ram`, `storage`, `branch`, `min_price`, `max_price`, plus `sort` (`newest`, `price_low`, `price_high`) and bounded `page` / `per_page` (maximum 48). Its pagination metadata contains page counts only; it never exposes database IDs or offsets.

Every returned record has already passed operational, publication and exact-price gates. A 200 response with `data: []` is a genuine empty result. A disabled or invalid connector is neutral and unavailable; it is never represented as empty inventory.

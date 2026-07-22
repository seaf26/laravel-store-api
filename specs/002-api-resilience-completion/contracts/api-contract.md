# API Contract Changes: Resilience Completion

Unlisted success bodies, authentication, and authorization remain unchanged.

## Product listing validation

Applies to `GET /api/products`.

Accepted query fields are documented in [data-model.md](../data-model.md). Invalid fields
use Laravel's existing validation response:

```http
HTTP/1.1 422 Unprocessable Content
Content-Type: application/json

{
  "message": "The max price field must be greater than or equal to min price.",
  "errors": {
    "max_price": ["The max price field must be greater than or equal to min price."]
  }
}
```

Invalid booleans, numeric values, sort/direction values, and pagination are no longer
silently cast or clamped.

## Order listing validation

Applies to `GET /api/orders`.

Unknown statuses, invalid users, sort/direction values, and non-positive or oversized
pagination return the same `422` envelope. `user_id` is accepted only for administrators;
a regular user receives a `user_id` validation error and remains scoped to their own data.

## Product media behavior

The existing create (`201`), update (`200`), and delete (`204`) success contracts remain
unchanged. A failed persistence operation keeps the previous database/image state. Old
file cleanup failure does not turn an already committed catalogue mutation into an HTTP
error; it is retained for scheduled retry.

## Notification behavior

Notification feed response shapes are unchanged. Retries or concurrent delivery of the
same recipient/event create one database notification. For notifications with SMS, the
database record becomes durable first; duplicate workers do not attempt SMS.

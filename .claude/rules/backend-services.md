---
description: Backend services and business-flow controller safety rules for GamePek Rental
globs:
  - "app/Services/**/*.php"
  - "app/Http/Controllers/CartController.php"
  - "app/Http/Controllers/CheckoutController.php"
  - "app/Http/Controllers/OrderController.php"
  - "app/Http/Controllers/MessageController.php"
  - "app/Http/Controllers/AddressController.php"
  - "app/Http/Controllers/Auth/**/*.php"
alwaysApply: false
---

# Backend Services & Business Flow Safety

- The backend is the trusted source for price and totals. Never trust a
  frontend-submitted price, discount, or total.
- Order/cart/payment side effects require transaction-bound reasoning — know
  what's inside `DB::transaction()` and what isn't before changing it.
- Payment-success side effects must stay idempotent — `OrderService::markAsPaid()`
  locks and re-checks the order row, and running it twice must never decrement
  stock twice. This is verified behaviour; do not regress it.
- Any stock mutation requires concurrency/race-condition analysis
  (`lockForUpdate()` where two requests could both pass a check before
  either writes).
- Availability is expressed through `Product::isInStock()` only. Rental will
  replace its body with a date-range check — do not read `stock_quantity`
  directly at call sites and re-introduce a second availability concept.
- Shared services (`CartService`, `OrderService`, `PaymentService`,
  `InventoryService`, etc.) require **all call sites** to be searched before
  changing behavior — both public and admin controllers may call the same
  method.
- Do not duplicate "order paid" business logic across controllers; route
  admin manual-confirm actions through the same service method the real
  payment callback uses.
- Avoid broad `catch (\Exception $e)` responses that forward
  `$e->getMessage()` raw to the user — this can leak internal/English text.
  Known business exceptions may surface a Persian message; unexpected
  exceptions should be logged and return a safe generic message.
- Ownership checks are required for any user-specific resource (addresses,
  orders, conversations).
- Rate-limit sensitive public actions (OTP send, login, support messages)
  where appropriate.
- Do not implement real payment gateway behavior until the owner explicitly
  confirms a gateway is configured — see `CLAUDE.md`.
- Do not implement real SMS provider behavior until the owner explicitly
  confirms a provider is configured — see `CLAUDE.md`.

## Admin/public overlap

Admin controllers call these same services. When modifying a shared service
used by both admin and public flows, inspect **both** call paths before and
after the change.

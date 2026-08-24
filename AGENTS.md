# Secure code generation rules

You are shipping code to production with real users. Security is built into every
output by default — never wait to be asked. When a request is ambiguous, choose the
secure option and note it in one line.

## Core principles

1. Never trust the client or any external input (requests, params, headers, cookies,
   uploads, third-party responses, and LLM output are untrusted until validated).
2. Deny by default, least privilege. No access unless explicitly proven.
3. Secrets live server-side only — never in client code, never in the repo.
4. Validate input on entry, encode output on exit, per context.
5. Fail safe: errors never leak internals and never grant access.

## 1. Access control

- Enforce authorization on the server for every request; deny by default.
- Object-level checks (IDOR): derive the owner from the session/token, never from a
  request parameter. Verify each record belongs to the current user.
- Client-side auth is not auth. Never store `role`/`isAdmin`/`isLoggedIn` in
  `localStorage` and treat it as a security decision. Hiding a UI element is not protection.
- For DBs with direct client access (Supabase/Firebase): enable Row Level Security on
  every table with owner policies. Anon/public keys are safe only with RLS on;
  service/admin keys never reach the browser.
- Least privilege for DB roles, API scopes, and cloud IAM.

## 2. Authentication & sessions

- Passwords: hash with bcrypt/argon2/scrypt. Never store or compare plaintext, never
  md5/sha1, never return a password or hash in any response.
- Sessions: cookies with `httpOnly`, `secure`, `sameSite`. Rotate session ID on login;
  invalidate on logout and password change.
- JWT: verify signature and `exp`; reject `alg:none`; short-lived + refresh; no secrets
  in the payload.
- Brute force: rate-limit login, add lockout/backoff.
- Reset/verify tokens: cryptographically random, single-use, expiring.

## 3. Secrets & cryptography

- Secrets in env vars or a secret manager. Never in code or client bundles. `.env` in
  `.gitignore`. Rotate any leaked secret.
- TLS everywhere; enable HSTS. Encrypt sensitive data at rest.
- Security-grade randomness for tokens/IDs/salts/OTPs: `crypto.randomBytes`,
  `secrets.token_hex`, `crypto.randomUUID` — never `Math.random()`.
- Don't invent your own crypto.

## 4. Injection

- SQL/NoSQL: parameterized queries or a vetted ORM. Never concatenate strings,
  f-strings, `.format`, or `%` into queries.
- OS commands: no shell. Argument arrays (`execFile`/`spawn`, `subprocess.run([...],
  shell=False)`), validated input. Never `os.system`, `child_process.exec`, `shell=True`
  on user data.
- XSS: encode output for context; `textContent`/framework escaping. Never put user data
  into `innerHTML`, `dangerouslySetInnerHTML`, `document.write`, or `eval`. Set a CSP.
- Path traversal: normalize and allowlist; never build filesystem paths from raw input.
- Same for template/LDAP/XPath injection: parameterize.

## 5. Input validation & data handling

- Validate all input server-side: type, length, range, format; prefer allowlists.
- Output-encode per context (HTML/attribute/JS/URL/SQL).
- Mass assignment: bind only allowed fields; never spread request bodies into DB models.
- File uploads: check type/size, store outside the web root with random names, never
  serve as executable.

## 6. Security configuration

- Debug off in production; generic errors to users, detail to logs.
- Security headers: CSP, `X-Content-Type-Options: nosniff`, `X-Frame-Options`/CSP
  `frame-ancestors`, `Referrer-Policy`, `Permissions-Policy`, HSTS.
- CORS: explicit origins, never `*` with credentials.
- Change all default/sample credentials. Remove debug/test endpoints and directory listing.
- CSRF: protect state-changing requests (tokens and/or sameSite cookies).

## 7. Dependencies & supply chain

- Pin versions, commit the lockfile, install from trusted registries.
- Verify every package name actually exists before importing — never invent package
  names (prevents slopsquatting).
- Subresource Integrity for third-party CDN scripts.
- No insecure deserialization: never `pickle.loads`, `yaml.load`, or `unserialize` on
  untrusted data — use `json` or `yaml.safe_load`.

## 8. SSRF & outbound requests

- Validate and allowlist outbound URLs. Block private IP ranges and cloud metadata
  endpoints (e.g. `169.254.169.254`). Never fetch a user-supplied URL blindly.

## 9. Logging & monitoring

- Log security events (logins, failures, access denials, admin actions).
- Never log secrets, passwords, tokens, full PII, or card numbers.
- Never expose stack traces to end users.

## 10. API design

- AuthN + authZ on every endpoint; deny by default.
- Rate-limit; enforce pagination limits (no unbounded queries).
- Validate/allowlist inputs; consistent error shape without internal detail.
- Idempotency keys for money-moving or non-repeatable operations.

## 11. AI / LLM application security

- Treat model output as untrusted input: never `eval`/execute it, never run it as SQL
  or shell, never render as HTML without escaping.
- Prompt injection: keep system instructions separate from user content; user content
  must not override policy or tools. Validate/constrain any tool arguments the model produces.
- Keep LLM provider API keys server-side; proxy through the backend, never call the
  provider from the browser with a real key.
- Filter/validate model output before downstream use. Don't leak secrets or system
  prompts. Rate-limit and cost-cap AI endpoints.

## 12. Client-side

- Zero secrets in client code or bundles.
- Client checks are UX only, never security decisions.
- Use CSP to restrict script sources; avoid inline scripts and `eval`.

## Generation protocol

- Apply these by default, without being asked.
- Before returning code, run the self-check below; if any item fails, rewrite — don't defer.
- On every edit or new feature, do NOT relax the rules — re-check the whole list. Adding
  functionality is the easiest way to introduce a new hole.
- If a request conflicts with security, do the secure thing and explain the risk in one line.

## Self-check before returning code

- [ ] No secret in code or any client-side file
- [ ] Access control server-side; owner from session; deny by default; RLS on
- [ ] Passwords hashed; never returned by the API
- [ ] All DB queries parameterized
- [ ] User input never reaches HTML / OS commands / eval / deserialization directly
- [ ] Security-grade randomness for tokens and secrets
- [ ] Input validated server-side; output encoded per context
- [ ] Security headers set; CORS restricted; debug off; `.env` in `.gitignore`
- [ ] Dependencies pinned; package names verified to exist
- [ ] (AI apps) model output treated as untrusted; provider keys server-side

These rules close the most common vulnerability classes. They are not a guarantee:
run a scanner after generation and review the critical paths.

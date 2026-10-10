# How OAuth credentials are stored and recovered

This package manages one account. It does not provide a profile manager or infer credential types from token prefixes. For login commands, see [authentication](auth.md).

## Registration and identity

The first authorization uses dynamic registration. The file store persists a stable installation UUID before login succeeds. The callback's issued client ID is retained even if token exchange fails.

Later authorization reuses that client ID. The application name hint is sent only during initial registration. A verified sign-in with granted scopes supplies an ID-token hint during reauthorization; a disconnected record does not.

The service uses PKCE S256 with fresh state and nonce. It verifies the ID token's RS256 signature against OpenAI JWKS, issuer, audience, expiration, nonce, and account identity. Reauthorization cannot silently change the verified account.

See OpenAI's [sign-in guide](https://developers.openai.com/siwc/token-sharing-open-source/sign-in) for the protocol.

## Saved records are not always usable grants

`AuthRecord` stores registration, verified identity, scopes, nonce, and nullable credentials.

| State | What remains saved | Can authorize inference? |
| --- | --- | --- |
| Pending registration | Issued client ID and authorization nonce | No |
| Identity-only sign-in | Verified identity and granted scopes; tokens may be present | No, without `chatgpt.tokens.use.direct` |
| Connected grant | Verified identity, direct-token scope, access and refresh tokens | Yes, after expiry checks |
| Pending refresh | Protected replacement grant awaiting verification | No |
| Disconnected | Issued registration and retained verified identity | No |
| Unusable refresh grant | Issued registration after token clearing | No |

Use `OAuthService::accessToken()` for inference. It rejects missing plan permission and resolves refresh when needed. A non-null record or access-token string alone is not sufficient.

## Shared storage and locks

`AuthFileStore` normalizes the credential path, updates only its ChatGPT entry, and preserves unrelated top-level records. It refuses a symbolic-link auth file. Writes use an atomic rename with mode `0600`.

Every worker must use the same credential path and a compatible lock backend. The service rereads credentials under the lock before refreshing, so a worker can use a grant another worker has already rotated.

Custom stores must preserve all `AuthRecord` fields and the optional `pendingRefresh` payload. Their `update()` operation must reread, mutate, and persist under one cross-worker lock. See the [storage contract](reference.md#storage).

## Recover after token rotation

A successful refresh may invalidate the old refresh token before a signing-key request succeeds. The service therefore saves the replacement grant before verifying its returned ID token.

1. Under the storage lock, receive the replacement token response.
2. Save a `PendingRefreshDTO` and remove the consumed active credentials.
3. Verify the replacement identity against the saved registration.
4. Promote the verified grant. If its access token has also expired, redeem the replacement refresh token before inference.

A failed JWKS fetch leaves the replacement pending. A retry, restarted process, or another worker resumes verification instead of submitting the consumed refresh token again. Pending access and identity never authorize inference.

The pending record includes the trusted `receivedAt` timestamp from the service clock. Verification checks the staged ID token at receipt time, so a JWKS outage lasting beyond that token's expiration does not destroy a valid rotation. A token expired or otherwise invalid at receipt still fails. Signature, issuer, audience, nonce, and account checks remain required.

Only protected pending storage supplies this timestamp. Missing receipt fields fail closed; recovery does not invent a timestamp or use a token claim. Invalid identity leaves pending storage unchanged. Ordinary verification and fresh login use the current clock.

Signing keys use Symfony Cache with a one-hour lifetime. An unknown key ID causes one new fetch. See [verification configuration](reference.md#verification-and-clocks) for shared cache and clock injection.

If refresh omits an ID token, the service retains the previously verified identity. A refreshed ID token may omit nonce; if it supplies nonce, the value must match the saved authorization.

## Handle unusable tokens and disconnect

Confirmed unusable refresh-token errors clear access, refresh, and ID tokens under the lock, then raise `AuthException`. The issued client ID remains available for login. Temporary failures, `invalid_client`, and unknown errors preserve the grant.

Disconnect discovers the remote revocation endpoint and revokes the active or pending replacement grant. If revocation fails, local credentials remain saved and the operation raises an error. Successful disconnect retains the registration and verified identity, but does not send an ID-token hint on the next login.

For official recovery guidance, see [errors and recovery](https://developers.openai.com/siwc/token-sharing-open-source/errors-and-recovery).

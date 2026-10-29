# Vulnerability scan status regression checklist

These checks must pass before deploying the backend change.

## 1. HTTP/API failure
- Mock a WPVulnerability request that returns a WP_Error (timeout/DNS/SSL).
- Expected: `failed` increments; the response has `incomplete: true`; a scan with zero findings must not render a success/clear state.
- Repeat the scan while the five-minute error transient exists.
- Expected: cached error marker still returns an unsuccessful lookup; it must not be treated as a clean empty database result.

## 2. HTTP non-200
- Mock 404, 429 and 5xx responses.
- Expected: `failed` increments; result is incomplete.

## 3. Invalid response
- Return HTTP 200 with malformed JSON, a scalar JSON document, missing `data`, missing `data.vulnerability`, or a non-array `data.vulnerability`.
- Expected: `failed` and `parse_failed` increment; result is incomplete.

## 4. Successful empty result
- Return HTTP 200 with a schema-valid response containing `data.vulnerability: []`.
- Expected: no failure; component is treated as successfully checked with no matching known vulnerabilities.

## 5. Missing local identity/version
- Submit a plugin or theme record with an empty slug or version.
- Expected: `skipped` increments and `incomplete: true`.

## 6. Scan time budget
- Simulate a scan where the time budget expires before all components are queried.
- Expected: `aborted: true` and `incomplete: true`.

## 7. UI semantics
- An incomplete scan with zero vulnerabilities must display the warning state and must not display the all-clear message.
- A complete scan with zero vulnerabilities may display: “照合が完了しました。既知の脆弱性情報は見つかりませんでした。” with a caveat that absence from the database does not guarantee absence of vulnerabilities.
- A scan that finds one or more vulnerabilities must display the findings, and must retain the incomplete warning if some other components were not checked.

## Deployment note
This checklist is not an automated test suite. Run it against a staging copy with mocked HTTP responses before deploying to the live API.

# Google BigQuery Transformation

Transformation component which runs SQL queries on BigQuery.

## Configuration

- `authorization` object (required): 
    - `workspace` object (required)
        - `schema` string (required)
        - `credentials` object (required)
            - ...
- `parameters`
    - `query_timeout` integer (optional, default `0`): timeout for a request in seconds, `0` means no timeout
    - `max_poll_retries` integer (optional, default `0`): upper bound on BigQuery job polling retries. `0` means unlimited (the SDK default); a positive value caps the exponential-backoff polling loop so a stuck job fails with a clear error instead of hanging until the component hard-timeout. As a rough guide, `200` ≈ 3 h, `500` ≈ 8 h of cumulative backoff.
    - `blocks` array (required): list of blocks
        - `name` string (required): name of the block
        - `codes` array (required): list of codes
            - `name` string (required): name of the code
            - `script` array (required): list of sql queries

Read about workspace credentials [here](https://developers.keboola.com/extend/common-interface/folders/#exchanging-data-via-workspace).

## Example configuration

Note that authorizations section is missing in the example. In production, the Job Runner injects credentials
automatically.

```json
{
  "parameters": {
    "blocks": [
      {
        "name": "first block",
        "codes": [
          {
            "name": "first code",
            "script": [
              "CREATE TABLE IF NOT EXISTS \"example\" (\"name\" VARCHAR(200),\"usercity\" VARCHAR(200));",
              "INSERT INTO \"example\" VALUES ('test example name', 'Prague'), ('test example name 2', 'Brno'), ('test example name 3', 'Ostrava')"
            ]
          }
        ]
      }
    ]
  }
}
```

## Sample credentials file

```json
{
  "project_id": "sapi-123",
  "private_key": "-----BEGIN PRIVATE KEY-----\nxxxxxxxxxxxxxxx\n-----END PRIVATE KEY-----\n",
  "token_uri": "https://oauth2.googleapis.com/token",
  "client_email": "sapi-workspace-222@sapi-123.iam.gserviceaccount.com",
  "client_id": "111",
  "auth_uri": "https://accounts.google.com/o/oauth2/auth",
  "auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
  "private_key_id": "444",
  "client_x509_cert_url": "https://www.googleapis.com/robot/v1/metadata/x509/sapi-workspace-222%40sapi-123.iam.gserviceaccount.com",
  "type": "service_account"
}
```

## Session variables export

After a successful transformation, all user-declared session variables
(top-level `DECLARE` statements in your block scripts) are written to
`out/result.json` in the format:

```json
{
    "variables": {
        "my_var": "value",
        "another_var": 42
    }
}
```

Notes:
- The file is only written when at least one user-declared variable exists.
- The file is **not** written if the transformation fails or is aborted via `ABORT_TRANSFORMATION`.
- Internal `KBC_*` variables and `ABORT_TRANSFORMATION` are excluded from the output.
- `ARRAY` and `STRUCT` typed variables are serialised as JSON-encoded strings (the downstream component-variable contract accepts only scalar values).
- `DATE`, `DATETIME`, `TIMESTAMP` and similar typed variables are serialised as ISO-8601 strings.
- Variables declared inside nested `BEGIN ... END` blocks are local and not exported.

**Known limitation:** the lightweight `DECLARE` parser stops at the first type
keyword (`STRING`, `INT64`, `DATE`, `JSON`, …). These keywords are *not*
reserved in BigQuery, so a variable named like a type is silently dropped — e.g.
`DECLARE date STRING DEFAULT '2026-01-01'` will not be exported, and in a list
such as `DECLARE a, date, c STRING` the parser stops at `date`, dropping `c`
too. Avoid naming session variables after BigQuery type keywords if you need
them exported. Fully disambiguating name- vs type-position would require a real
SQL tokenizer.

## Development

Clone this repository.

Build the image:
```shell
docker-compose build
```

Install dependencies:
```shell
docker-compose run --rm dev composer install --no-scripts
```

Create `.env` file from template `.env.dist` and fill it with data from Credentials File.
As `BQ_DATASET` use the name of the dataset, e.g. `WORKSPACE_222`.

Run the test suites:
```shell
docker-compose run --rm dev composer tests
```

## License

Check LICENSE file.

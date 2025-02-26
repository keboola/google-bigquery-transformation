# Google BigQuery Transformation

The BigQuery transformation component executes SQL queries directly within Google BigQuery.

## Configuration

- `authorization` object (required): 
    - `workspace` object (required)
        - `schema` string (required)
        - `credentials` object (required)
            - ...
- `parameters`:
    - `query_timeout` integer (optional, default `0`): Timeout for a request in seconds. Set to `0` for no timeout.
    - `blocks` array (required): List of blocks
        - `name` string (required): Name of the query block
        - `codes` array (required): List of codes
            - `name` string (required): Name of the code
            - `script` array (required): List of SQL queries

Read about workspace credentials [here](https://developers.keboola.com/extend/common-interface/folders/#exchanging-data-via-workspace).

## Example Configuration

**Note:** The authorizations section is ommitted here; in production, credentials are injected automatically by the Job Runner.

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

## Sample Credentials File

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

Create a `.env` file from the template `.env.dist` and populate it with the appropriate credentials.
Se tje dataset name (`BQ_DATASET`) accordingly, e.g., `WORKSPACE_222`.

Run the test suites:
```shell
docker-compose run --rm dev composer tests
```

## License

Refer to the LICENSE file for licensing details.

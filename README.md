# Google BigQuery Transformation

Transformation component which runs SQL queries on BigQuery.

## Configuration

- `authorization` object (required): 
    - `workspace` object (required)
        - `schema` string (required)
        - `credentials` object (required)
            - ...
- `parameters`
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

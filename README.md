# Google BigQuery Transformation

Application which runs KBC transformations

## Options

- `authorization` object (required): [workspace credentials](https://developers.keboola.com/extend/common-interface/folders/#exchanging-data-via-workspace)
- `parameters`
    - `blocks` array (required): list of blocks
        - `name` string (required): name of the block
        - `codes` array (required): list of codes
            - `name` string (required): name of the code
            - `script` array (required): list of sql queries

## Example configuration

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

## Development
 
Clone this repository and init the workspace with following command:

```shell
git clone https://github.com/keboola/google-bigquery-transformation
cd google-bigquery-transformation
docker-compose build
docker-compose run --rm dev composer install --no-scripts
```

Create `.env` file from template `.env.dist` and fill it with JSON BigQuery credentials

Run the test suite using this command:

```shell
docker-compose run --rm dev composer tests
```
 
# Integration

For information about deployment and integration with KBC, please refer to the [deployment section of developers documentation](https://developers.keboola.com/extend/component/deployment/) 

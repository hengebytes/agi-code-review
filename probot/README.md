# agi-code-review

> A GitHub App built with [Probot](https://github.com/probot/probot) that Code Review powered by AI, with context from Jira ticket

## Setup

```sh
# Install dependencies
npm install

# Run the bot
npm start
```

## Docker

```sh
# 1. Build container
docker build -t agi-code-review .

# 2. Start container
docker run -e APP_ID=<app-id> -e PRIVATE_KEY=<pem-value> agi-code-review
```

## License

[ISC](LICENSE) © 2024 Ivan Ternovtsii

## TBD

#### Actions executed automatically
- (every 30 min) Get new requests from GitHub and create tasks for them
- (every 10 min) Process all tasks that are ready to be processed

#### Interactive Commands (`docker-compose exec php php bin/console agi:*`)
```bash
# Create new project
agi:project:create

# Create new agent config
agi:agent:create

# Add configured agent to existing project
agi:project:agents:add

# Process tasks in ready to process state
agi:tasks:process

# Check for new requests from GitHub, and create tasks for each new request
agi:github:check-new-requests

# Create task for a specific pull request (by URL)
agi:github:create-pr-task
```

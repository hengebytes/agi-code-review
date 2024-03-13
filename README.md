## Roadmap
- [x] Core functionality with projects with tasks processed by agents
- [x] Local AI integration
- [x] Dockerize the application
- [x] Agents to collect and preprocess data from Jira (summary from Jira issue comments, sensitive information removal)
- [x] GitHub and GitLab pull request integration (pull diffs and perform code review analysis)
- [x] GitHub ongoing feedback processing (take in account previously provided feedback after new commits)
- [x] Slack notification integration
- [x] GitHub probot integration for live updates
- [x] LLM api calls cache
- [ ] Process GitHub updates without probot (by cronjob)
- [ ] GitLab ongoing feedback processing (take in account previously provided feedback after new commits)
- [ ] Build UI for projects, tasks, and agents management
- [ ] OS notification agent
- [ ] Propose pull request changes (from AI-created new branch into PR branch)
- [ ] A whoel codebase RAG with Jira context based on commit messages
- [ ] Create task from Jira issue, and let AI open pull request with task completed

#### Actions executed automatically
- (every 5 min) Get new requests from GitHub and create tasks for them
- (every 5 min) Get new requests from GitLab and create tasks for them
- (every 5 min) Process all tasks that are ready to be processed

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

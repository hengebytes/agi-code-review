import { Probot } from "probot";

export = (app: Probot) => {
  app.on([
    "pull_request.opened",
    "pull_request.closed",
    "pull_request.synchronize",
    "pull_request.reopened",
  ], async (context) => {
    const pull_request = context.payload.pull_request;
    if (pull_request.locked || pull_request.draft) {
      return;
    }

    const repo = context.repo();
    try {
      const res = await fetch("http://php/api/webhook/github/pr_update", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify({
          owner: repo.owner,
          repo: repo.repo,
          prId: pull_request.number,
          status: pull_request.state,
        }),
      });
      console.log("PR updated", res.statusText);
    } catch (e) {
      console.error(e);
    }
  });
};

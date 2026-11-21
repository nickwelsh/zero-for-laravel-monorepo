# GitHub issue tracker

Use `gh` for `nickwelsh/zero-for-laravel-monorepo`. Confirm the target with `gh repo view --json nameWithOwner` before the first issue creation in a session.

## Issues

- Publish a spec or ticket with `gh issue create --title "..." --body-file <path>`.
- Read a ticket with `gh issue view <number> --json number,title,body,labels,comments`.
- List tickets with `gh issue list --state open --json number,title,body,labels,comments`. Use `--label` and `--state` to narrow the result.
- Apply or remove labels with `gh issue edit <number> --add-label "..."` or `--remove-label "..."`.
- Comment with `gh issue comment <number> --body-file <path>`. To close an issue, post the reason first, then run `gh issue close <number>`.

## Relationships

- Link sub-issues with `gh issue create --parent <parent>` or `gh issue edit <parent> --add-sub-issue <child>`.
- Link blockers with `gh issue create --blocked-by <n>,<n>` or `gh issue edit <child> --add-blocked-by <blocker>`.
- On older `gh` versions, use `gh api --method POST repos/{owner}/{repo}/issues/<parent>/sub_issues -F sub_issue_id=<child-db-id>` for a sub-issue or `gh api --method POST repos/{owner}/{repo}/issues/<child>/dependencies/blocked_by -F issue_id=<blocker-db-id>` for a blocker. Get the numeric database ID with `gh api repos/{owner}/{repo}/issues/<number> --jq .id`.
- When GitHub relationships are unavailable, record `Part of #<parent>` or `Blocked by: #<n>, #<n>` at the top of the ticket body.
- Count open native blockers with `gh api repos/{owner}/{repo}/issues/<number> --jq .issue_dependencies_summary.blocked_by`. For blockers listed in the body, check the state of each referenced issue. A ticket is unblocked when every blocker is closed.

## Pull requests as a triage surface

**PRs as a request surface: no.**

When enabled, apply the issue triage roles to external PRs using `gh pr view`, `gh pr diff`, `gh pr comment`, `gh pr edit`, and `gh pr close`. List open PRs with `gh api --paginate 'repos/{owner}/{repo}/pulls?state=open'` and exclude associations `OWNER`, `MEMBER`, and `COLLABORATOR`. Resolve a bare `#number` with `gh pr view` first, then `gh issue view`.

## Wayfinding operations

- Create the map as an issue labeled `wayfinder:map` with Notes, Decisions-so-far, and Fog sections.
- Link child tickets as sub-issues. If sub-issues are unavailable, list children in the map's task list and add `Part of #<map>` to each child. Use the corresponding `wayfinder:research`, `wayfinder:prototype`, `wayfinder:grilling`, or `wayfinder:task` label.
- Find the first open child in map order with zero open blockers and no assignee.
- Claim it with `gh issue edit <number> --add-assignee @me` as the session's first write.
- Post the resolution, close the child, and append the decision and its link to Decisions-so-far on the map.

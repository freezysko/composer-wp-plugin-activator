#!/usr/bin/env bash
set -euo pipefail

REPO="${REPO:-freezysko/composer-wp-plugin-activator}"
BRANCH="${BRANCH:-main}"
SETTINGS="${SETTINGS:-.github/branch-protection.json}"

echo "Applying branch protection to ${REPO}@${BRANCH} from ${SETTINGS}..."

# Main protection rules
gh api \
  --method PUT \
  -H "Accept: application/vnd.github+json" \
  "/repos/${REPO}/branches/${BRANCH}/protection" \
  --input "${SETTINGS}"

# required_signatures is a separate sub-resource on the GitHub API; the main
# /protection PUT does not honour the `required_signatures` JSON key, so this
# second call is required.
gh api \
  --method POST \
  -H "Accept: application/vnd.github+json" \
  "/repos/${REPO}/branches/${BRANCH}/protection/required_signatures"

echo "Done. Verify with: gh api /repos/${REPO}/branches/${BRANCH}/protection"

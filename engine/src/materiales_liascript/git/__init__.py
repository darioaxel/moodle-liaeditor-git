"""GitProvider (interfaz) + LocalGitProvider: lectura, worktrees aislados y commits plumbing."""

from materiales_liascript.git.local_provider import LocalGitProvider
from materiales_liascript.git.provider import (
    BranchMovedError,
    GitProvider,
    GitProviderError,
    MergeTree,
)

__all__ = [
    "BranchMovedError",
    "GitProvider",
    "GitProviderError",
    "LocalGitProvider",
    "MergeTree",
]

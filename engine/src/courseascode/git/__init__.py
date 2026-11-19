"""GitProvider (interfaz) + LocalGitProvider: lectura, worktrees aislados y commits plumbing."""

from courseascode.git.local_provider import LocalGitProvider
from courseascode.git.provider import BranchMovedError, GitProvider, GitProviderError

__all__ = [
    "BranchMovedError",
    "GitProvider",
    "GitProviderError",
    "LocalGitProvider",
]

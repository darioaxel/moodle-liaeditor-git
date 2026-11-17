"""GitProvider (interfaz) + LocalGitProvider (GitPython): worktrees aislados, tags, diffs."""

from courseascode.git.diff import BookDiff, Diff, FileChange
from courseascode.git.local_provider import LocalGitProvider
from courseascode.git.provider import BranchMovedError, GitProvider, GitProviderError
from courseascode.git.versioning import VersionResolver, detect_source

__all__ = [
    "BookDiff",
    "BranchMovedError",
    "Diff",
    "FileChange",
    "GitProvider",
    "GitProviderError",
    "LocalGitProvider",
    "VersionResolver",
    "detect_source",
]

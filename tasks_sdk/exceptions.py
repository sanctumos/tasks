"""
Custom exceptions for the Tasks SDK.
"""


class TasksError(Exception):
    """Base exception for all Tasks SDK errors."""
    pass


class APIError(TasksError):
    """Raised when the API returns an error response."""
    
    def __init__(self, message: str, status_code: int = None, response: dict = None):
        super().__init__(message)
        self.status_code = status_code
        self.response = response


class AuthenticationError(APIError):
    """Raised when authentication fails (invalid or missing API key)."""
    pass


class NotFoundError(APIError):
    """Raised when a requested resource is not found."""
    pass


class ValidationError(APIError):
    """Raised when request validation fails."""
    pass

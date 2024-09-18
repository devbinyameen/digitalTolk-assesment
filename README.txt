I have refactored the code by removing unnecessary lines and implementing the following improvements:

- Introduced Interface classes to enforce strict parameter and response structures, ensuring system stability and preventing accidental disruptions. This approach ensures all changes are made consciously.
- Developed Service providers and Repository bindings for the Interface classes, allowing for seamless replacement of service classes while maintaining clear business logic within the service layer.
- Implemented the Service Repository pattern to separate business logic from database transactions, organizing them into distinct classes.
- Applied business logic in Service classes and handled database transactions within Repository classes for better code organization.
- Added Requests for validation to enhance optimization and reusability, centralizing validation logic to maintain a single source of truth.
- Replaced nested if-else blocks with return statements for improved readability and flow.
- Standardized ambiguous responses using dedicated response classes for consistent formatting.


P.s: Unfortunetly didn't get the time for writing test cases.
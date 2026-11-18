# PROJECT CONTEXT — POINT OF SALES APPLICATION

## 1. Project Overview

This project is a standalone web-based Point of Sales (POS) application.

The objective is to build a professional, modern, scalable, and user-friendly POS platform with an intuitive user experience inspired by Moka POS.

The application must prioritize operational efficiency, transaction speed, usability, and maintainability.

## 2. Product Vision

Build a commercial-grade POS application that:
- Is easy to learn and operate.
- Supports efficient daily transactions.
- Has intuitive navigation.
- Provides consistent user experience.
- Supports the actual business requirements.
- Can evolve as business requirements grow.

## 3. Project Scope

CURRENT SCOPE: POS APPLICATION ONLY.

Included:
- POS dashboard.
- Sales transactions.
- Cashier interface.
- Product management.
- Inventory management where applicable.
- Customer basic management.
- Payment management.
- Transaction history.
- Reporting.
- User and role management.
- Existing operational modules.

Excluded:
- CRM marketing automation.
- Customer loyalty campaigns.
- Advanced customer segmentation.
- CRM engagement management.
- CRM-specific analytics.
- Other independent CRM functionalities.

Do not introduce features outside the existing business requirements without approval.

## 4. UI/UX Direction

Primary reference: Moka POS.

Design characteristics:
- Clean and minimalist.
- Modern SaaS interface.
- Intuitive navigation.
- Consistent design system.
- Clear visual hierarchy.
- Efficient cashier workflow.
- Responsive interface.
- Reusable UI components.

Do not copy proprietary assets or source code from reference applications.

## 5. Business Process Direction

Prioritize:
- Simple transaction workflows.
- Minimum unnecessary interactions.
- Accurate transaction calculations.
- Consistent payment processing.
- Reliable inventory handling.
- Clear transaction history.
- Appropriate role-based permissions.

Analyze existing workflows before changing business logic.

## 6. Architecture Principles

- POS operates independently.
- Maintain existing project architecture where appropriate.
- Keep frontend and backend responsibilities clear.
- Maintain database integrity.
- Use modular and maintainable code.
- Avoid unnecessary dependencies.
- Preserve existing functionality.

## 7. Future CRM Integration

CRM will be developed as a separate project with its own frontend, backend, database, and business logic.

Future integration will happen through secure APIs.

Prepare:
- Stable entity identifiers.
- Consistent transaction references.
- Documented API contracts.
- Clear data ownership.
- Synchronization readiness.

Do not implement CRM functionality in this project.

POS must remain fully operational without CRM.

## 8. Development Rules

1. Audit before modifying.
2. Understand existing code before refactoring.
3. Do not delete existing functionality without approval.
4. Do not make unapproved production database changes.
5. Preserve existing business rules unless explicitly approved for improvement.
6. Implement changes incrementally.
7. Test every significant modification.
8. Document architectural decisions.

## 9. Development Priority

1. Existing application audit.
2. UI/UX redesign.
3. Business process optimization.
4. POS functionality improvement.
5. Database and API validation.
6. Testing and stabilization.
7. Future CRM integration documentation.

## 10. Core Development Principle

Always prioritize the POS product vision and current project scope.

Do not make assumptions about business requirements.

When requirements are unclear, inspect the existing implementation and request clarification before making significant architectural or business logic changes.

## DEVELOPMENT EXECUTION POLICY

The primary objective is to BUILD, IMPROVE, AND COMPLETE
the existing POS application.

Documentation and codebase audits are supporting activities,
not the final deliverables.

After the initial audit and approval of the implementation plan,
the AI Agent must actively execute the development tasks.

Do not stop at providing recommendations, mockups, or reports.

Implement actual working features, integrate frontend and backend,
validate database operations, and test the application.

Continue development incrementally until the POS application
meets the defined product requirements.

CRM development is explicitly outside the current scope.
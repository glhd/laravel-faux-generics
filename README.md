# Faux Generics

This package lets you generate Laravel-specific helper files to simulate 
generics in PHP. This provides better type hinting and refactoring for
many of Laravel's fluent and proxy features:

 - [x] Models (return types and static methods)
 - [x] Query Builder (return types, pass-thru, and forward calls)
 - [x] Collections (return types)
 - [x] Higher Order Collection Proxies (type-hint on property and method chains)
 - [x] Model Scopes (type-hint on builder, relations, etc)
 - [x] Factories (type-hint on `factory()` calls)
 - [ ] Paginator
 - [x] Macros

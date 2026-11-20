# Changelog

## [0.1.2](https://github.com/SpeedySpec/speedyspec-cli-wordpress/compare/v0.1.1...v0.1.2) (2026-11-20)


### Features

* install and maintain WordPress on Kubernetes ([#7](https://github.com/SpeedySpec/speedyspec-cli-wordpress/issues/7)) ([24a3b8b](https://github.com/SpeedySpec/speedyspec-cli-wordpress/commit/24a3b8b0a16e961c6961dc57f697d5369211615d))


### Build System

* require contracts ^1.5 for development ([#9](https://github.com/SpeedySpec/speedyspec-cli-wordpress/issues/9)) ([07db972](https://github.com/SpeedySpec/speedyspec-cli-wordpress/commit/07db972421ef5432d9840ea5e15bb1862460343a))

## [0.1.1](https://github.com/SpeedySpec/speedyspec-cli-wordpress/compare/v0.1.0...v0.1.1) (2026-11-19)


### Features

* keep post GUIDs unchanged when a restore moves the site ([#3](https://github.com/SpeedySpec/speedyspec-cli-wordpress/issues/3)) ([494a464](https://github.com/SpeedySpec/speedyspec-cli-wordpress/commit/494a46443848d218deabf2416f344ff10f27f9f3))
* tell speedyspec what a WordPress backup holds ([#1](https://github.com/SpeedySpec/speedyspec-cli-wordpress/issues/1)) ([6eff6fc](https://github.com/SpeedySpec/speedyspec-cli-wordpress/commit/6eff6fc492ad69a0fcb14ace39fa5bb72b82f3eb))
* the WordPress app plugin for speedyspec ([0a0d9c4](https://github.com/SpeedySpec/speedyspec-cli-wordpress/commit/0a0d9c46b2b4f78a05fdd7be4b3edc9f0ee0f2fc))


### Bug Fixes

* keep e2e out of the source plugin's checkout, and name the temporary wp-config so it is never served ([df88dda](https://github.com/SpeedySpec/speedyspec-cli-wordpress/commit/df88dda30bad0ab31e6302e6e25ecab95a6ba396))


### Build System

* require contracts 1.3, and check the moved widget and wp-config.php in e2e ([#2](https://github.com/SpeedySpec/speedyspec-cli-wordpress/issues/2)) ([7697d46](https://github.com/SpeedySpec/speedyspec-cli-wordpress/commit/7697d46e138bc45394f950996dc18c361c482570))


### Continuous Integration

* release with Release Please and tell Packagist about each release ([#4](https://github.com/SpeedySpec/speedyspec-cli-wordpress/issues/4)) ([3f62a5b](https://github.com/SpeedySpec/speedyspec-cli-wordpress/commit/3f62a5b197e595e970c4ffb5443d5d656bc9204f))

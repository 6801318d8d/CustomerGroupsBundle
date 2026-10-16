<?php

namespace KimaiPlugin\CustomerGroupsBundle\EventSubscriber;

use App\Event\ThemeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Turns the customer headers of the project lists into a tree: every customer starts closed and a click on it
 * opens or closes its projects; while searching, the customers with matching projects are open. Every select list
 * also grows down to the bottom of the window instead of TomSelect's fixed 200px.
 *
 * Kimai renders project selects with TomSelect, grouped by customer (ProjectType "group_by"), and has no setting for
 * either behaviour. TomSelect rebuilds the group elements on every render (opening, typing), so a MutationObserver
 * re-applies the open/closed state after each render. Only the project selects (id "..._project" or "projects")
 * become collapsible; the other lists only get the taller box.
 */
final class CustomerGroupsSubscriber implements EventSubscriberInterface
{
    private const STYLE = <<<'HTML'
        <style>
        .ts-dropdown.kcg-projects .ts-dropdown-content { padding-top: 0; }
        .ts-dropdown.kcg-projects .optgroup::before { display: none; }
        .ts-dropdown.kcg-projects .optgroup-header.kcg-header {
            position: sticky;
            top: 0;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: .5rem;
            padding: .5rem .75rem;
            background: var(--tblr-bg-surface);
            color: var(--tblr-body-color);
            font-size: .875rem;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
        }
        .ts-dropdown.kcg-projects * + .optgroup > .kcg-header { border-top: 1px solid var(--tblr-border-color-translucent); }
        .ts-dropdown.kcg-projects .optgroup-header.kcg-header:hover { background: var(--tblr-bg-surface-secondary, #f6f8fb); }
        .ts-dropdown.kcg-projects .kcg-header::before {
            content: "";
            flex: none;
            width: .4rem;
            height: .4rem;
            margin: 0 .2rem .2rem;
            border-right: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: rotate(45deg);
            transition: transform .15s;
        }
        .ts-dropdown.kcg-projects .kcg-closed > .kcg-header::before { margin-bottom: 0; transform: rotate(-45deg); }
        .ts-dropdown.kcg-projects .kcg-header::after {
            content: attr(data-count);
            margin-left: auto;
            color: var(--tblr-secondary);
            font-size: .75rem;
            font-weight: 400;
        }
        .ts-dropdown.kcg-projects .kcg-closed > .option { display: none; }
        .ts-dropdown.kcg-projects .optgroup > .option {
            padding-left: 2.25rem;
            background-image: linear-gradient(var(--tblr-border-color), var(--tblr-border-color));
            background-position: 1.15rem 0;
            background-repeat: no-repeat;
            background-size: 1px 100%;
        }
        </style>
        HTML;

    private const SCRIPT = <<<'HTML'
        <script>
        (function () {
            const SUFFIX = '-ts-dropdown';
            const states = new WeakMap();

            // TomSelect gives its list the id "<select id>-ts-dropdown" and stores itself on the select
            function getTomSelect(content) {
                if (!content.id.endsWith(SUFFIX)) {
                    return null;
                }
                const select = document.getElementById(content.id.slice(0, -SUFFIX.length));

                return select !== null && select.tomselect !== undefined ? select.tomselect : null;
            }

            function isProjectSelect(tomselect) {
                return /(^|_)projects?$/.test(tomselect.inputId);
            }

            // "open": customers opened by the user; "searchClosed": customers closed while searching
            function getState(tomselect) {
                let state = states.get(tomselect);
                if (state === undefined) {
                    state = {open: new Set(), searchClosed: new Set(), query: ''};
                    states.set(tomselect, state);
                    // every time the list opens, all customers start closed
                    tomselect.on('dropdown_close', function () {
                        state.open.clear();
                        state.searchClosed.clear();
                    });
                }

                return state;
            }

            function isShown(content, option) {
                return content.contains(option) && option.closest('.kcg-closed') === null;
            }

            function applyGroups(content, tomselect) {
                const state = getState(tomselect);
                const query = tomselect.inputValue();
                if (query !== state.query) {
                    state.query = query;
                    state.searchClosed.clear();
                }

                for (const group of content.querySelectorAll(':scope > .optgroup')) {
                    const header = group.querySelector(':scope > .optgroup-header');
                    if (header === null) {
                        continue;
                    }
                    const label = header.textContent;
                    const open = query !== '' ? !state.searchClosed.has(label) : state.open.has(label);
                    const options = group.querySelectorAll(':scope > .option');
                    header.classList.add('kcg-header');
                    header.dataset.count = options.length;
                    group.classList.toggle('kcg-closed', !open);
                    // hidden projects must not be reachable with the arrow keys or Enter
                    for (const option of options) {
                        if (open && option.dataset.kcgHidden !== undefined) {
                            option.setAttribute('data-selectable', '');
                            delete option.dataset.kcgHidden;
                        } else if (!open && option.hasAttribute('data-selectable')) {
                            option.removeAttribute('data-selectable');
                            option.dataset.kcgHidden = '';
                        }
                    }
                }

                // TomSelect highlights an option before this runs: never leave the highlight on a hidden project
                const active = tomselect.activeOption;
                if (active === null || !isShown(content, active)) {
                    const first = query !== '' ? content.querySelector('[data-selectable]') : null;
                    if (first !== null) {
                        tomselect.setActiveOption(first);
                    } else if (active !== null) {
                        tomselect.clearActiveOption();
                    }
                }
            }

            // let the list reach the bottom of the window (at least TomSelect's 200px)
            function fitHeight(dropdown) {
                const content = dropdown.querySelector('.ts-dropdown-content');
                if (content === null || dropdown.style.display === 'none') {
                    return;
                }
                const height = Math.max(200, Math.floor(window.innerHeight - content.getBoundingClientRect().top - 12)) + 'px';
                if (content.style.maxHeight !== height) {
                    content.style.maxHeight = height;
                }
            }

            function toggleGroup(event) {
                const header = event.target instanceof Element ? event.target.closest('.kcg-header') : null;
                if (header === null) {
                    return;
                }
                // keep the focus in the select (TomSelect closes the list on blur) and stop TomSelect's handlers
                event.preventDefault();
                event.stopPropagation();
                const content = header.closest('.ts-dropdown-content');
                const tomselect = content !== null ? getTomSelect(content) : null;
                if (tomselect === null) {
                    return;
                }
                const state = getState(tomselect);
                const set = tomselect.inputValue() !== '' ? state.searchClosed : state.open;
                const label = header.textContent;
                if (set.has(label)) {
                    set.delete(label);
                } else {
                    set.add(label);
                }
                applyGroups(content, tomselect);

                // scroll the opened projects into view, without pushing the customer out of the top
                const group = header.parentElement;
                if (!group.classList.contains('kcg-closed')) {
                    const box = content.getBoundingClientRect();
                    const rect = group.getBoundingClientRect();
                    const overflow = rect.bottom - box.bottom;
                    if (overflow > 0) {
                        content.scrollTop += Math.min(overflow, rect.top - box.top);
                    }
                }
            }

            function init() {
                window.addEventListener('mousedown', toggleGroup, true);

                const observer = new MutationObserver(function (mutations) {
                    const dropdowns = new Set();
                    const contents = new Set();
                    for (const mutation of mutations) {
                        const target = mutation.target;
                        if (target.nodeType !== Node.ELEMENT_NODE) {
                            continue;
                        }
                        // TomSelect sets the style of the list when opening it and when the window scrolls or resizes
                        if (mutation.type === 'attributes') {
                            if (target.classList.contains('ts-dropdown')) {
                                dropdowns.add(target);
                            }
                        } else if (target.classList.contains('ts-dropdown-content')) {
                            contents.add(target);
                        }
                    }
                    for (const content of contents) {
                        const tomselect = getTomSelect(content);
                        if (tomselect !== null && isProjectSelect(tomselect)) {
                            content.parentElement.classList.add('kcg-projects');
                            applyGroups(content, tomselect);
                        }
                    }
                    dropdowns.forEach(fitHeight);
                });
                observer.observe(document.body, {childList: true, subtree: true, attributes: true, attributeFilter: ['style']});
            }

            if (document.body !== null) {
                init();
            } else {
                document.addEventListener('DOMContentLoaded', init);
            }
        })();
        </script>
        HTML;

    public static function getSubscribedEvents(): array
    {
        return [
            ThemeEvent::STYLESHEET => ['onStylesheet', 100],
            ThemeEvent::JAVASCRIPT => ['onJavascript', 100],
        ];
    }

    public function onStylesheet(ThemeEvent $event): void
    {
        // the login page triggers the events too, without user and without selects
        if ($event->getUser() !== null) {
            $event->addContent(self::STYLE);
        }
    }

    public function onJavascript(ThemeEvent $event): void
    {
        if ($event->getUser() !== null) {
            $event->addContent(self::SCRIPT);
        }
    }
}

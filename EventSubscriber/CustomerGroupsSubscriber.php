<?php

namespace KimaiPlugin\CustomerGroupsBundle\EventSubscriber;

use App\Event\ThemeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns the customer headers of the project lists into a tree: every customer starts closed and a click on it
 * opens or closes its projects; while searching, the customers with matching projects are open. A list with the
 * projects of one customer only (e.g. the form has a customer selected) shows them without the header. Every select
 * list also grows down to the bottom of the window instead of TomSelect's fixed 200px, and on touch screens opens
 * without the keyboard (a second tap on the open select brings it up to search). On phones the popups with the forms
 * take the whole width of the screen: Bootstrap keeps a margin around them, and on touch screens the popup's own
 * scrollbar goes (Tabler styles scrollbars 1rem wide with ::-webkit-scrollbar, so on Android the popup kept a strip
 * on the right; the page's scrollbar is Android's, drawn over it).
 *
 * In the timesheet forms, choosing a customer also selects a project: the only one of that customer (Kimai does this
 * itself), otherwise the project of the user's latest entry for that customer (API "get_timesheets", one entry), if it
 * is in the list; a project chosen in the meantime is kept.
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
        .ts-dropdown.kcg-single .optgroup-header { display: none; }
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
        @media (max-width: 575.98px) {
            .modal { padding-left: 0 !important; padding-right: 0 !important; }
            .modal-dialog { margin: 0; max-width: none; }
            .modal-content { border-left: 0; border-right: 0; border-radius: 0; }
        }
        /* the popup scrolls itself, and Tabler's ::-webkit-scrollbar is 1rem wide and takes room: none on touch screens */
        @media (pointer: coarse) {
            .modal::-webkit-scrollbar { width: 0; height: 0; }
        }
        </style>
        HTML;

    private const SCRIPT = <<<'HTML'
        <script>
        (function () {
            const TIMESHEETS_URL = __CUSTOMER_GROUPS_TIMESHEETS_URL__;
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

            // counts the customers of all the projects in the list, not only of the ones matching the search
            function countGroups(tomselect) {
                const field = tomselect.settings.optgroupField;
                const groups = new Set();
                for (const option of Object.values(tomselect.options)) {
                    if (option[field] !== undefined) {
                        groups.add(option[field]);
                    }
                }

                return groups.size;
            }

            function applyGroups(content, tomselect) {
                const state = getState(tomselect);
                const query = tomselect.inputValue();
                if (query !== state.query) {
                    state.query = query;
                    state.searchClosed.clear();
                }

                // the projects of one customer (e.g. the form has a customer selected) need no tree and no header
                const single = countGroups(tomselect) < 2;
                content.parentElement.classList.toggle('kcg-projects', !single);
                content.parentElement.classList.toggle('kcg-single', single);

                for (const group of content.querySelectorAll(':scope > .optgroup')) {
                    const header = group.querySelector(':scope > .optgroup-header');
                    if (header === null) {
                        continue;
                    }
                    const label = header.textContent;
                    const open = single || (query !== '' ? !state.searchClosed.has(label) : state.open.has(label));
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

            // touch screens: TomSelect puts the cursor in its search box at once, and the phone's keyboard then covers
            // half of the list. With a finger, a tap opens the list without the keyboard (inputmode "none"); a tap on
            // the select while its list is open brings the keyboard up to type a search. Mouse and pen are untouched.
            function touchWithoutKeyboard() {
                let typing = null;
                document.addEventListener('pointerdown', function (event) {
                    if (event.pointerType !== 'touch' || !(event.target instanceof Element)) {
                        return;
                    }
                    const wrapper = event.target.closest('.ts-wrapper');
                    const input = wrapper !== null ? wrapper.querySelector('.ts-control input') : null;
                    if (input === null) {
                        return;
                    }
                    if (!wrapper.classList.contains('dropdown-active')) {
                        input.inputMode = 'none';
                    } else if (document.activeElement === input && input.inputMode === 'none') {
                        typing = input;
                    }
                }, true);
                // the keyboard only shows when the box gets the focus: take it away and give it back, during the tap
                document.addEventListener('click', function () {
                    if (typing === null) {
                        return;
                    }
                    const input = typing;
                    typing = null;
                    input.blur();
                    input.inputMode = 'text';
                    input.focus();
                }, true);
            }

            // Kimai's API client, with the session of the page
            let api = null;
            document.addEventListener('kimai.initialized', function (event) {
                try {
                    api = event.detail.kimai.getPlugin('api');
                } catch (error) {
                    console.warn('CustomerGroupsBundle: no API client, no default project', error);
                }
            });

            // runs "callback" once the select that "select" reloads (e.g. the activities of a project) is not loading:
            // Kimai ignores a new change while that list is still loading
            function whenRelatedIdle(select, callback) {
                const related = select.dataset.relatedSelect ? document.getElementById(select.dataset.relatedSelect) : null;
                if (related === null || related.dataset.reloading !== '1') {
                    callback();
                    return;
                }
                // "data-reloaded" fires before Kimai marks the list as loaded
                related.addEventListener('data-reloaded', function () { setTimeout(callback, 0); }, {once: true});
            }

            // Timesheet forms: when a customer is chosen, Kimai loads its projects and selects one only if it is the
            // only one. Otherwise select the project of the user's latest entry for that customer, if it is in the list.
            // A project chosen in the meantime, or another customer, wins.
            function defaultProject() {
                // "data-reloaded" does not bubble: listen while it goes down to the select
                document.addEventListener('data-reloaded', function (event) {
                    const project = event.target;
                    if (!(project instanceof HTMLSelectElement) || project.multiple || project.id === '') {
                        return;
                    }
                    const customer = document.querySelector('select[data-related-select="' + CSS.escape(project.id) + '"]');
                    if (customer === null || customer.multiple || !/^timesheet_\w*\[customer\]$/.test(customer.name) || customer.value === '') {
                        return;
                    }
                    const values = [...project.options].map(option => option.value).filter(value => value !== '');
                    const customerId = customer.value;
                    const pick = function (value) {
                        if (customer.value !== customerId || project.value !== '' || !values.includes(value)) {
                            return;
                        }
                        whenRelatedIdle(project, function () {
                            if (customer.value !== customerId || project.value !== '') {
                                return;
                            }
                            if (project.tomselect !== undefined) {
                                // also triggers "change", so that Kimai loads the activities of the project
                                project.tomselect.setValue(value);
                            } else {
                                project.value = value;
                                project.dispatchEvent(new Event('change', {bubbles: true}));
                            }
                        });
                    };
                    // Kimai sets the value before this event and triggers "change" after it: decide afterwards
                    setTimeout(function () {
                        if (project.value !== '' || values.length === 0) {
                            return;
                        }
                        if (values.length === 1) {
                            pick(values[0]);
                            return;
                        }
                        if (api === null) {
                            return;
                        }
                        const query = {'customers[]': customerId, size: 1, orderBy: 'begin', order: 'DESC'};
                        api.get(TIMESHEETS_URL, query, function (timesheets) {
                            const latest = Array.isArray(timesheets) && timesheets.length > 0 ? timesheets[0] : null;
                            const id = latest !== null ? latest.project : null;
                            if (id !== null && id !== undefined) {
                                pick(String(typeof id === 'object' ? id.id : id));
                            }
                        }, function (error) {
                            console.warn('CustomerGroupsBundle: cannot read the latest entry', error);
                        });
                    }, 0);
                }, true);
            }

            function init() {
                window.addEventListener('mousedown', toggleGroup, true);
                touchWithoutKeyboard();
                defaultProject();

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

    public function __construct(private readonly UrlGeneratorInterface $router)
    {
    }

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
            $url = json_encode($this->router->generate('get_timesheets'), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);
            $event->addContent(str_replace('__CUSTOMER_GROUPS_TIMESHEETS_URL__', $url, self::SCRIPT));
        }
    }
}

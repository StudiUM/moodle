<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace core;

/**
 * Tests that fix_course_sortorder() is lazy.
 *
 * It used to renumber the whole flat depth first order of the categories, so creating, deleting or
 * moving a single category rewrote the sortorder of every category that followed it and of every
 * single course of the site. These tests count the queries it executes and check which records it
 * really writes.
 *
 * @package    core
 * @category   test
 * @copyright  2026 Jonathan Champ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::fix_course_sortorder
 */
final class fix_course_sortorder_test extends \advanced_testcase {
    /**
     * Create a tree of course categories with a couple of courses in each of the leaf categories.
     * The categories are inserted like \core_course_category::create() does it and the whole tree
     * is then placed by a single call of fix_course_sortorder().
     *
     * @param int $topcategories number of the top level categories
     * @param int $subcategories number of the subcategories of each top level category
     * @param int $courses number of the courses in each subcategory
     * @return array [topcategoryid => [subcategoryid, ...], ...] the top level categories
     */
    protected function create_category_tree(int $topcategories, int $subcategories, int $courses = 2): array {
        $tree = [];
        for ($t = 1; $t <= $topcategories; $t++) {
            $parentid = $this->insert_category(0, "Top $t");
            $tree[$parentid] = [];
            for ($s = 1; $s <= $subcategories; $s++) {
                $childid = $this->insert_category($parentid, "Sub $t.$s");
                $tree[$parentid][] = $childid;
                for ($c = 1; $c <= $courses; $c++) {
                    $this->getDataGenerator()->create_course(['category' => $childid]);
                }
            }
        }

        // Place all the new categories in the tree.
        fix_course_sortorder();

        return $tree;
    }

    /**
     * Insert a single course category the same way \core_course_category::create() does it.
     *
     * @param int $parentid
     * @param string $name
     * @return int the id of the new category
     */
    protected function insert_category(int $parentid, string $name): int {
        global $DB;

        $parentpath = '';
        $depth = 1;
        if ($parentid) {
            $parent = $DB->get_record('course_categories', ['id' => $parentid], '*', MUST_EXIST);
            $parentpath = $parent->path;
            $depth = $parent->depth + 1;
        }

        $record = (object) [
            'name' => $name,
            'idnumber' => '',
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'parent' => $parentid,

            // The category is not placed in the tree yet.
            'sortorder' => 0,
            'coursecount' => 0,
            'visible' => 1,
            'visibleold' => 1,
            'timemodified' => time(),
            'depth' => $depth,
            'path' => '',
            'theme' => '',
        ];
        $id = $DB->insert_record('course_categories', $record);

        // The id of the new category is a part of its own path, the path is set once the id is known.
        $DB->set_field('course_categories', 'path', $parentpath . '/' . $id, ['id' => $id]);

        return $id;
    }

    /**
     * Move a category the same way \core_course_category::change_parent_raw() does it, except that
     * the path and the depth are updated here too (that would only add noise to the counts).
     *
     * @param int $categoryid the category to move
     * @param int $newparentid the new parent of the category
     */
    protected function move_category(int $categoryid, int $newparentid): void {
        global $DB;

        $newparent = $DB->get_record('course_categories', ['id' => $newparentid], '*', MUST_EXIST);
        $DB->set_field('course_categories', 'parent', $newparentid, ['id' => $categoryid]);
        $DB->set_field('course_categories', 'depth', $newparent->depth + 1, ['id' => $categoryid]);
        $DB->set_field(
            'course_categories',
            'path',
            $newparent->path . '/' . $categoryid,
            ['id' => $categoryid]
        );

        // The moved category belongs to the end of its new parent.
        $DB->set_field('course_categories', 'sortorder', 0, ['id' => $categoryid]);
    }

    /**
     * Call fix_course_sortorder() and count the database queries it executed.
     *
     * @return array [number of read queries, number of write queries]
     */
    protected function count_queries_of_fix(): array {
        global $DB;

        $reads = $DB->perf_get_reads();
        $writes = $DB->perf_get_writes();
        fix_course_sortorder();

        return [$DB->perf_get_reads() - $reads, $DB->perf_get_writes() - $writes];
    }

    /**
     * Return the sortorder of all the categories and courses.
     *
     * @return array [categories => [id => sortorder], courses => [id => sortorder]]
     */
    protected function get_sortorders(): array {
        global $DB;

        $categories = [];
        foreach ($DB->get_records('course_categories', null, 'id', 'id, sortorder') as $cat) {
            $categories[$cat->id] = (int) $cat->sortorder;
        }

        $courses = [];
        foreach ($DB->get_records('course', null, 'id', 'id, sortorder') as $course) {
            $courses[$course->id] = (int) $course->sortorder;
        }

        return [$categories, $courses];
    }

    /**
     * Return the categories and courses whose sortorder differs from the given snapshot.
     *
     * @param array $snapshot the value returned by get_sortorders()
     * @return array [categories => [id => [before, after]], courses => [id => [before, after]]]
     */
    protected function get_sortorder_changes(array $snapshot): array {
        [$categories, $courses] = $this->get_sortorders();
        [$oldcategories, $oldcourses] = $snapshot;

        $changes = ['categories' => [], 'courses' => []];
        foreach ($categories as $id => $sortorder) {
            if (!array_key_exists($id, $oldcategories)) {
                $changes['categories'][$id] = [null, $sortorder];
            } else if ($oldcategories[$id] !== $sortorder) {
                $changes['categories'][$id] = [$oldcategories[$id], $sortorder];
            }
        }
        foreach ($courses as $id => $sortorder) {
            if (!array_key_exists($id, $oldcourses)) {
                $changes['courses'][$id] = [null, $sortorder];
            } else if ($oldcourses[$id] !== $sortorder) {
                $changes['courses'][$id] = [$oldcourses[$id], $sortorder];
            }
        }

        return $changes;
    }

    /**
     * Return the ids of all the categories in the depth first order of the tree.
     *
     * @return int[]
     */
    protected function get_depth_first_categories(): array {
        global $DB;

        $categories = $DB->get_records('course_categories', null, 'sortorder, id');
        $children = [];
        $top = [];
        foreach ($categories as $cat) {
            $sortorder = (int) $cat->sortorder;
            if (!$cat->parent) {
                while (isset($top[$sortorder])) {
                    $sortorder++;
                }
                $top[$sortorder] = $cat;
                continue;
            }
            if (!isset($categories[$cat->parent])) {
                // Dangling parent, fix_course_sortorder() moves such a category to the top level.
                while (isset($top[$sortorder])) {
                    $sortorder++;
                }
                $top[$sortorder] = $cat;
                continue;
            }
            while (isset($children[$cat->parent][$sortorder])) {
                $sortorder++;
            }
            $children[$cat->parent][$sortorder] = $cat;
        }

        $result = [];
        $walk = function (array $cats) use (&$walk, $children, &$result) {
            foreach ($cats as $cat) {
                $result[] = (int) $cat->id;
                if (isset($children[$cat->id])) {
                    $walk($children[$cat->id]);
                }
            }
        };
        $walk($top);

        return $result;
    }

    /**
     * Assert that all the invariants verified by fix_course_sortorder() are satisfied.
     */
    protected function assert_invariants_hold(): void {
        global $DB;

        $step = get_max_courses_in_category();
        $categories = $DB->get_records('course_categories', null, 'sortorder, id');
        $this->assertNotEmpty($categories);

        // Rebuild the tree the very same way fix_course_sortorder() does it.
        $children = [];
        $top = [];
        foreach ($categories as $cat) {
            $sortorder = (int) $cat->sortorder;
            if (!$cat->parent) {
                while (isset($top[$sortorder])) {
                    $sortorder++;
                }
                $top[$sortorder] = $cat;
                continue;
            }
            while (isset($children[$cat->parent][$sortorder])) {
                $sortorder++;
            }
            $children[$cat->parent][$sortorder] = $cat;
        }

        $nextsortorder = $step;
        $walk = function (
            array $cats,
            string $parentpath,
            int $parentdepth
        ) use (
            &$walk,
            &$nextsortorder,
            $children,
            $step
        ) {
            foreach ($cats as $cat) {
                $this->assertGreaterThanOrEqual(
                    $nextsortorder,
                    (int) $cat->sortorder,
                    "The category {$cat->id} is too close to the previous category of the depth first order."
                );
                $this->assertSame(
                    $parentpath . '/' . $cat->id,
                    $cat->path,
                    "The path of the category {$cat->id} does not match the tree."
                );
                $this->assertSame(
                    $parentdepth + 1,
                    (int) $cat->depth,
                    "The depth of the category {$cat->id} does not match the tree."
                );
                $nextsortorder = (int) $cat->sortorder + $step;
                if (isset($children[$cat->id])) {
                    $walk($children[$cat->id], $cat->path, (int) $cat->depth);
                }
            }
        };
        $walk($top, '', 0);

        // Every course inside the range of its category, no duplicated sortorder, correct count.
        $sql = 'SELECT category, COUNT(*) AS coursecount, COUNT(DISTINCT sortorder) AS distinctcount,
                       MIN(sortorder) AS minsortorder, MAX(sortorder) AS maxsortorder
                  FROM {course}
              GROUP BY category';
        $summary = $DB->get_records_sql($sql);
        foreach ($categories as $cat) {
            $coursecount = empty($summary[$cat->id]) ? 0 : (int) $summary[$cat->id]->coursecount;
            $this->assertSame(
                (int) $cat->coursecount,
                $coursecount,
                "The course count of the category {$cat->id} is not up to date."
            );
            if (empty($coursecount)) {
                continue;
            }
            $courses = $summary[$cat->id];
            $this->assertSame(
                $coursecount,
                (int) $courses->distinctcount,
                "Two courses of the category {$cat->id} share the sortorder."
            );
            $this->assertGreaterThan(
                (int) $cat->sortorder,
                (int) $courses->minsortorder,
                "A course of the category {$cat->id} is not behind the sortorder of the category."
            );
            $this->assertLessThanOrEqual(
                (int) $cat->sortorder + $step,
                (int) $courses->maxsortorder,
                "A course of the category {$cat->id} is outside of the sortorder range of the category."
            );
        }
    }

    /**
     * Return the courses of a category ordered by the sortorder.
     *
     * @param int $categoryid
     * @return array
     */
    protected function get_courses_of(int $categoryid): array {
        global $DB;

        return $DB->get_records('course', ['category' => $categoryid], 'sortorder, id', 'id, sortorder');
    }

    /**
     * A healthy tree is verified with a constant number of queries and without a single write.
     */
    public function test_healthy_tree_is_not_rewritten(): void {
        $this->resetAfterTest();

        $this->create_category_tree(3, 3, 2);
        $this->assert_invariants_hold();

        [$reads, $writes] = $this->count_queries_of_fix();

        // One query for the categories, one for the front page course, one for all the courses.
        $this->assertLessThanOrEqual(
            5,
            $reads,
            'fix_course_sortorder() should not scan the courses more than once.'
        );
        $this->assertSame(0, $writes, 'fix_course_sortorder() should not write anything for a healthy tree.');
    }

    /**
     * The number of the queries must not grow with the number of the categories and courses.
     */
    public function test_query_count_does_not_grow_with_the_size_of_the_site(): void {
        $this->resetAfterTest();

        $this->create_category_tree(2, 2, 1);
        [$reads, $writes] = $this->count_queries_of_fix();

        // Now make the site a lot bigger.
        $this->create_category_tree(5, 5, 2);
        [$bigreads, $bigwrites] = $this->count_queries_of_fix();
        $this->assert_invariants_hold();

        $this->assertSame(
            $reads,
            $bigreads,
            'The number of the queries grew with the number of the categories and courses.'
        );
        $this->assertSame(0, $writes);
        $this->assertSame(0, $bigwrites);
    }

    /**
     * Deleting a category only leaves a gap in the sortorder of the categories behind it.
     */
    public function test_deleting_a_category_does_not_shift_the_other_categories(): void {
        global $DB;

        $this->resetAfterTest();

        $tree = $this->create_category_tree(3, 3, 2);
        $snapshot = $this->get_sortorders();
        $parentid = array_key_first($tree);
        $deletedid = $tree[$parentid][1];

        // Delete a category in the middle of the tree together with its courses.
        $DB->delete_records('course', ['category' => $deletedid]);
        $DB->delete_records('course_categories', ['id' => $deletedid]);

        [, $writes] = $this->count_queries_of_fix();

        // The only record that may be written here is the stale course count of the parent.
        $this->assertLessThanOrEqual(
            1,
            $writes,
            'Deleting a category must not renumber any other category or course.'
        );
        $changes = $this->get_sortorder_changes($snapshot);
        $this->assertSame([], $changes['categories'], 'No category may be renumbered.');
        $this->assertSame([], $changes['courses'], 'No course may be renumbered.');
        $this->assert_invariants_hold();
    }

    /**
     * Creating a category writes the sortorder of the new category only.
     *
     * The new category is created in a category that already has children, so that it really has to
     * be placed behind them and not in front of them.
     */
    public function test_creating_a_category_does_not_shift_the_other_categories(): void {
        global $DB;

        $this->resetAfterTest();

        $tree = $this->create_category_tree(3, 3, 2);
        $snapshot = $this->get_sortorders();

        // A top level category with three subcategories, and it is the last one in the tree, so
        // its new subcategory can be placed behind them without renumbering anything else.
        $parentid = array_key_last($tree);
        $newid = $this->insert_category($parentid, 'New category');

        [, $writes] = $this->count_queries_of_fix();

        // The new category is the only record that has to be written.
        $this->assertLessThanOrEqual(1, $writes);
        $changes = $this->get_sortorder_changes($snapshot);
        unset($changes['categories'][$newid]);
        $this->assertSame([], $changes['categories'], 'No existing category may be renumbered.');
        $this->assertSame([], $changes['courses'], 'No existing course may be renumbered.');
        $this->assert_invariants_hold();

        // The new category has to be the last child of its new parent.
        $newsortorder = (int) $DB->get_field('course_categories', 'sortorder', ['id' => $newid]);
        $this->assertGreaterThan($snapshot[0][$parentid], $newsortorder);
        foreach ($tree[$parentid] as $siblingid) {
            $this->assertGreaterThan(
                $snapshot[0][$siblingid],
                $newsortorder,
                "The new category must be behind its sibling {$siblingid}."
            );
        }
    }

    /**
     * Giving a first child to every top level category costs one cascade in total.
     *
     * A childless top level category has no branch, so nothing is reserved after it and the first
     * one renumbers everything behind it. That one cascade has to leave room for all the others.
     */
    public function test_giving_a_first_child_to_every_category_cascades_once(): void {
        $this->resetAfterTest();

        $topcategories = 6;
        $topids = [];
        for ($i = 1; $i <= $topcategories; $i++) {
            $topids[] = $this->insert_category(0, "Top $i");
        }
        // A course in each one, so the fix has counts to maintain as the tree is built.
        foreach ($topids as $topid) {
            $this->getDataGenerator()->create_course(['category' => $topid]);
        }
        $this->count_queries_of_fix();

        $cascades = 0;
        foreach ($topids as $index => $topid) {
            $snapshot = $this->get_sortorders();
            $newid = $this->insert_category($topid, "Child of top {$topids[$index]}");

            [, $writes] = $this->count_queries_of_fix();
            $changes = $this->get_sortorder_changes($snapshot);

            if ($index === 0) {
                // Only the very first one may renumber, the others have to fit into the room it left.
                $this->assertGreaterThan(
                    1,
                    $writes,
                    "The first child of the top category {$topid} has to renumber the categories behind it."
                );
                $cascades++;
            } else {
                $this->assertLessThanOrEqual(
                    1,
                    $writes,
                    "Only the new category {$newid} may be written, the rest of the tree is untouched."
                );
                unset($changes['categories'][$newid]);
                $this->assertSame(
                    [],
                    $changes['categories'],
                    "Giving the category {$topid} its first child must not renumber anything else."
                );
                $this->assertSame([], $changes['courses'], 'No existing course may be renumbered.');
            }
            $this->assert_invariants_hold();
        }

        $this->assertSame(1, $cascades, 'Only the first child may cost a cascade.');
    }

    /**
     * The room left behind by a cascade is not consumed by the fix itself.
     *
     * The following calls must find the tree valid and leave it exactly as it is.
     */
    public function test_the_room_left_by_a_cascade_survives_the_following_fixes(): void {
        $this->resetAfterTest();

        $topids = [];
        for ($i = 1; $i <= 4; $i++) {
            $topids[] = $this->insert_category(0, "Top $i");
        }
        foreach ($topids as $topid) {
            $this->getDataGenerator()->create_course(['category' => $topid]);
        }

        // The cascade that opens the gaps.
        $this->insert_category($topids[0], 'First child');
        $this->count_queries_of_fix();
        $this->assert_invariants_hold();
        $placed = $this->get_sortorders();

        // Nothing has changed since, so the gaps must be still there and nothing may be written.
        foreach (range(1, 3) as $run) {
            [, $writes] = $this->count_queries_of_fix();
            $this->assertSame(0, $writes, "Run {$run} must not write anything at all.");
            $this->assertSame($placed, $this->get_sortorders(), "Run {$run} moved the sortorders.");
        }
        $this->assert_invariants_hold();
    }

    /**
     * A cascade stops at the space a deleted category leaves behind in a tree that already has it.
     *
     * Earlier cascades left a reservation behind every category, so deleting one merges the two
     * around it into a hole that absorbs what follows. A packed tree has nothing to absorb that and
     * the cascade reaches the end instead, which is the second half of this test.
     */
    public function test_a_cascade_stops_at_the_space_a_deleted_category_left_behind(): void {
        global $DB;

        $this->resetAfterTest();

        $tree = $this->create_category_tree(6, 6, 2);

        // One cascade near the front, this is what puts the reservations into the tree.
        $this->insert_category(array_key_first($tree), 'Spreader');
        $this->count_queries_of_fix();
        $this->assert_invariants_hold();
        $this->assertGreaterThan(
            2,
            count($this->get_depth_first_categories()),
            'The test needs a tree that is bigger than the spreading cascade.'
        );

        $order = $this->get_depth_first_categories();
        $total = count($order);

        // Delete a category a bit further along, then add one a bit earlier, in the same run.
        $deletedindex = (int) round($total * 0.20);
        $parentindex = (int) round($total * 0.15);
        $this->assertLessThan($deletedindex, $parentindex, 'The new category has to come first.');
        $deletedid = $order[$deletedindex];
        $parentid = $order[$parentindex];
        $DB->delete_records('course', ['category' => $deletedid]);
        $DB->delete_records('course_categories', ['id' => $deletedid]);
        $newid = $this->insert_category($parentid, 'New child');

        $snapshot = $this->get_sortorders();
        $this->count_queries_of_fix();
        $this->assert_invariants_hold();

        $changes = $this->get_sortorder_changes($snapshot);
        unset($changes['categories'][$newid]);
        $renumbered = $changes['categories'];

        // The cascade has to stop at the hole, so almost nothing behind the new category moves.
        $this->assertLessThanOrEqual(
            3,
            count($renumbered),
            'The cascade must stop at the space the deleted category left behind.'
        );
    }

    /**
     * A packed tree has no space for the cascade to stop at, so it reaches the end of the tree.
     */
    public function test_a_cascade_reaches_the_end_of_a_packed_tree(): void {
        global $DB;

        $this->resetAfterTest();

        $tree = $this->create_category_tree(6, 6, 2);
        $order = $this->get_depth_first_categories();
        $total = count($order);
        $deletedid = $order[(int) round($total * 0.20)];
        $parentid = $order[(int) round($total * 0.15)];

        $DB->delete_records('course', ['category' => $deletedid]);
        $DB->delete_records('course_categories', ['id' => $deletedid]);
        $newid = $this->insert_category($parentid, 'New child');

        $snapshot = $this->get_sortorders();
        $this->count_queries_of_fix();
        $this->assert_invariants_hold();

        $changes = $this->get_sortorder_changes($snapshot);
        unset($changes['categories'][$newid]);

        $this->assertGreaterThan(
            20,
            count($changes['categories']),
            'A packed tree has no space to stop at, the cascade has to reach the end of it.'
        );
    }

    /**
     * A new child goes behind the children its parent already has.
     */
    public function test_a_new_child_is_behind_the_children_of_its_new_parent(): void {
        global $DB;

        $this->resetAfterTest();

        $tree = $this->create_category_tree(2, 3, 2);

        // The top level category, it has three children. The subcategories are leaves and would
        // make this test prove nothing.
        $parentid = array_key_first($tree);
        $siblingids = $tree[$parentid];
        $this->assertCount(3, $siblingids, 'The test needs a parent with more than one child.');

        $newid = $this->insert_category($parentid, 'New child');
        [, $writes] = $this->count_queries_of_fix();
        $this->assert_invariants_hold();

        // The new child goes behind the ones the parent already had, never in front of them.
        $siblings = $DB->get_records('course_categories', ['parent' => $parentid], 'sortorder, id', 'id');
        $this->assertSame($newid, (int) array_key_last($siblings), 'The new child is not the last one.');
        foreach ($siblingids as $siblingid) {
            $this->assertGreaterThan(
                (int) $DB->get_field('course_categories', 'sortorder', ['id' => $siblingid]),
                (int) $DB->get_field('course_categories', 'sortorder', ['id' => $newid]),
                "The new child must be behind its sibling {$siblingid}."
            );
        }
    }

    /**
     * The first category added in the middle of the tree renumbers the categories behind it, the
     * room that is left for it makes the next ones free.
     */
    public function test_adding_more_categories_in_the_same_place(): void {
        $this->resetAfterTest();

        $tree = $this->create_category_tree(3, 3, 2);

        // The first top level category, it has three subcategories and it is not the last one.
        $parentid = array_key_first($tree);

        // There is no free slot behind the last subcategory, so this one renumbers the categories
        // behind it and leaves room for a few more.
        $firstid = $this->insert_category($parentid, 'New category 1');
        [, $writes] = $this->count_queries_of_fix();
        $this->assert_invariants_hold();
        $changes = $this->get_sortorder_changes($this->get_sortorders());

        for ($i = 2; $i <= 5; $i++) {
            $snapshot = $this->get_sortorders();
            $newid = $this->insert_category($parentid, "New category $i");

            [, $writes] = $this->count_queries_of_fix();

            $this->assertLessThanOrEqual(1, $writes, "Only the new category {$newid} may be written.");
            $changes = $this->get_sortorder_changes($snapshot);
            unset($changes['categories'][$newid]);
            $this->assertSame(
                [],
                $changes['categories'],
                "Adding the category {$newid} must not renumber any existing category."
            );
            $this->assertSame(
                [],
                $changes['courses'],
                "Adding the category {$newid} must not renumber any existing course."
            );
            $this->assert_invariants_hold();
        }
    }

    /**
     * move_courses() asks for one sortorder per course moved at once, and those must all be inside
     * the range of the category even when none of its courses is placed yet.
     */
    public function test_free_course_sortorders_stay_inside_the_category_range(): void {
        global $DB;

        $this->resetAfterTest();

        $tree = $this->create_category_tree(1, 1, 2);
        $categoryid = reset($tree)[0];

        // Make every course of the category unplaced again, as a restore leaves them.
        $courses = array_keys($this->get_courses_of($categoryid));
        foreach ($courses as $courseid) {
            $DB->set_field('course', 'sortorder', 0, ['id' => $courseid]);
        }
        $catsortorder = (int) $DB->get_field('course_categories', 'sortorder', ['id' => $categoryid]);
        $sortorderlimit = $catsortorder + get_max_courses_in_category();

        foreach ([1, 2, 3, 5] as $count) {
            $sortorders = get_free_course_sortorders($categoryid, $count);
            $this->assertCount($count, $sortorders);
            $this->assertSame($sortorders, array_unique($sortorders));
            foreach ($sortorders as $sortorder) {
                $this->assertGreaterThan($catsortorder, $sortorder);
                $this->assertLessThanOrEqual($sortorderlimit, $sortorder);
            }
        }
    }

    /**
     * A new course is listed in front of the courses already in the category, and none of them moves.
     */
    public function test_a_new_course_goes_in_front_of_the_courses_that_are_already_there(): void {
        global $DB;

        $this->resetAfterTest();

        $tree = $this->create_category_tree(1, 1, 2);
        $categoryid = reset($tree)[0];
        $existing = array_keys($this->get_courses_of($categoryid));
        $snapshot = $this->get_sortorders();

        $newestid = (int) $this->getDataGenerator()->create_course(['category' => $categoryid])->id;

        [, $writes] = $this->count_queries_of_fix();
        $this->assertSame(0, $writes, 'The new course should have been given a free sortorder.');
        $this->assert_invariants_hold();

        $this->assertSame(
            array_merge([$newestid], $existing),
            array_keys($this->get_courses_of($categoryid)),
            'The newest course must be the first one in the category.'
        );

        $changes = $this->get_sortorder_changes($snapshot);
        unset($changes['courses'][$newestid]);
        $this->assertSame([], $changes['courses'], 'The sortorder of an existing course may not change.');
    }

    /**
     * Moving a category stacks its courses against the top of the new range, so the next course
     * can go in front of them.
     */
    public function test_the_courses_of_a_moved_category_are_stacked_against_the_top_of_its_range(): void {
        global $DB;

        $this->resetAfterTest();

        $tree = $this->create_category_tree(2, 2, 2);
        $firstsubcategories = reset($tree);
        $movedid = reset($firstsubcategories);
        $lastsubcategories = end($tree);
        $newparentid = end($lastsubcategories);
        $before = array_keys($this->get_courses_of($movedid));
        $this->assertGreaterThan(1, count($before));

        $this->move_category($movedid, $newparentid);
        $this->count_queries_of_fix();
        $this->assert_invariants_hold();

        $catsortorder = (int) $DB->get_field('course_categories', 'sortorder', ['id' => $movedid], MUST_EXIST);
        $sortorderlimit = $catsortorder + get_max_courses_in_category();
        $sortorders = [];
        foreach ($before as $courseid) {
            $sortorders[$courseid] = (int) $DB->get_field('course', 'sortorder', ['id' => $courseid], MUST_EXIST);
        }

        $this->assertSame(
            $before,
            array_keys($this->get_courses_of($movedid)),
            'Moving a category must not reorder its courses.'
        );
        $this->assertSame(
            $sortorderlimit - 1,
            max($sortorders),
            'The courses of a moved category should sit against the top of its new range.'
        );

        // Which leaves the space in front of them free for the next course.
        $newestid = (int) $this->getDataGenerator()->create_course(['category' => $movedid])->id;
        $this->assert_invariants_hold();
        $this->assertSame(
            array_merge([$newestid], $before),
            array_keys($this->get_courses_of($movedid))
        );
    }

    /**
     * A broken sortorder is repaired in front of the courses, where a new course goes as well.
     */
    public function test_a_broken_course_is_repaired_in_front_when_there_is_room(): void {
        global $DB;

        $this->resetAfterTest();

        $tree = $this->create_category_tree(1, 1, 3);
        $categoryid = reset($tree)[0];
        $courses = array_keys($this->get_courses_of($categoryid));
        $lastid = end($courses);

        // A restored course arrives with a sortorder of 0.
        $DB->set_field('course', 'sortorder', 0, ['id' => $lastid]);

        [, $writes] = $this->count_queries_of_fix();
        $this->assertLessThanOrEqual(1, $writes);
        $this->assert_invariants_hold();

        $this->assertSame(
            array_merge([$lastid], array_slice($courses, 0, -1)),
            array_keys($this->get_courses_of($categoryid)),
            'The repaired course should be placed in front of the others.'
        );
    }

    /**
     * Without any room in front, a repaired course has to go behind the last one.
     */
    public function test_a_broken_course_is_repaired_behind_when_there_is_no_room_in_front(): void {
        global $DB;

        $this->resetAfterTest();

        $tree = $this->create_category_tree(1, 1, 3);
        $categoryid = reset($tree)[0];
        $catsortorder = (int) $DB->get_field('course_categories', 'sortorder', ['id' => $categoryid], MUST_EXIST);

        // Stack them against the start of the range, the way an unreorganised site has them.
        $courses = array_keys($this->get_courses_of($categoryid));
        foreach ($courses as $index => $courseid) {
            $DB->set_field('course', 'sortorder', $catsortorder + 1 + $index, ['id' => $courseid]);
        }
        $this->count_queries_of_fix();

        $brokenid = $courses[1];
        $DB->set_field('course', 'sortorder', 0, ['id' => $brokenid]);

        [, $writes] = $this->count_queries_of_fix();
        $this->assertLessThanOrEqual(1, $writes);
        $this->assert_invariants_hold();

        $this->assertSame(
            [$courses[0], $courses[2], $brokenid],
            array_keys($this->get_courses_of($categoryid)),
            'Without any room in front the repaired course has to go behind the last one.'
        );
    }

    /**
     * Gaps in the course sortorder are a valid state and are never fixed.
     */
    public function test_gaps_in_the_course_sortorder_are_tolerated(): void {
        global $DB;

        $this->resetAfterTest();

        $tree = $this->create_category_tree(2, 2, 3);
        $categoryid = reset($tree)[0];
        $snapshot = $this->get_sortorders();
        $courses = array_keys($this->get_courses_of($categoryid));
        $lastid = end($courses);

        // Delete the first course of the category, this leaves a gap behind.
        $DB->delete_records('course', ['id' => $courses[0]]);
        [, $writes] = $this->count_queries_of_fix();
        $this->assertLessThanOrEqual(1, $writes, 'Only the stale course count may be updated.');
        $this->assert_invariants_hold();

        // Move the last course into the free space in front, which leaves a gap behind it.
        $remaining = array_keys($this->get_courses_of($categoryid));
        $newsortorder = $snapshot[1][$remaining[0]] - 10;
        $DB->set_field('course', 'sortorder', $newsortorder, ['id' => $lastid]);
        [, $writes] = $this->count_queries_of_fix();
        $this->assertSame(0, $writes, 'A gap in the course sortorder is a valid state.');
        $this->assertSame($newsortorder, (int) $DB->get_field('course', 'sortorder', ['id' => $lastid]));
        $this->assert_invariants_hold();
    }

    /**
     * Only the course with a really broken sortorder is rewritten.
     */
    public function test_duplicated_course_sortorder_is_fixed_locally(): void {
        global $DB;

        $this->resetAfterTest();

        $tree = $this->create_category_tree(3, 3, 2);
        $subcategories = end($tree);
        $categoryid = end($subcategories);
        $courses = array_keys($this->get_courses_of($categoryid));

        // Give the first course of the category the sortorder of the second one.
        $sortorders = $this->get_sortorders();
        $DB->set_field('course', 'sortorder', $sortorders[1][$courses[1]], ['id' => $courses[0]]);

        // The broken state is the baseline here, only the changes done by the fix are counted.
        $snapshot = $this->get_sortorders();

        [, $writes] = $this->count_queries_of_fix();

        $this->assertLessThanOrEqual(1, $writes);
        $changes = $this->get_sortorder_changes($snapshot);
        $this->assertCount(1, $changes['courses'], 'Only the broken course of the category may be rewritten.');
        $this->assertSame([], $changes['categories']);
        $this->assert_invariants_hold();

        // Running it again does not change anything at all.
        [, $writes] = $this->count_queries_of_fix();
        $this->assertSame(0, $writes);
    }

    /**
     * A course left behind above the sortorder range of its category is moved back in.
     */
    public function test_course_sortorder_above_the_category_range_is_fixed(): void {
        global $DB;

        $this->resetAfterTest();

        $tree = $this->create_category_tree(2, 2, 2);
        $categoryid = reset($tree)[0];
        $courses = array_keys($this->get_courses_of($categoryid));
        $catsortorder = (int) $DB->get_field('course_categories', 'sortorder', ['id' => $categoryid]);
        $sortorderlimit = $catsortorder + get_max_courses_in_category();

        $DB->set_field('course', 'sortorder', $sortorderlimit + 1, ['id' => $courses[0]]);

        [, $writes] = $this->count_queries_of_fix();

        $this->assertLessThanOrEqual(1, $writes);
        $this->assert_invariants_hold();
        $sortorder = (int) $DB->get_field('course', 'sortorder', ['id' => $courses[0]]);
        $this->assertGreaterThan($catsortorder, $sortorder);
        $this->assertLessThanOrEqual($sortorderlimit, $sortorder);
    }

    /**
     * Moving a category to the end of the tree renumbers the moved category only.
     */
    public function test_moving_a_category_to_the_end_of_the_tree(): void {
        global $DB;

        $this->resetAfterTest();

        $tree = $this->create_category_tree(3, 3, 2);
        $snapshot = $this->get_sortorders();
        $firstsubcategories = reset($tree);
        $movedid = reset($firstsubcategories);
        $lastsubcategories = end($tree);
        $newparentid = end($lastsubcategories);

        $this->move_category($movedid, $newparentid);

        [, $writes] = $this->count_queries_of_fix();

        // The moved category and its courses are the only records that have to be written.
        $this->assertLessThanOrEqual(3, $writes);
        $changes = $this->get_sortorder_changes($snapshot);
        $this->assertSame(
            [$movedid],
            array_keys($changes['categories']),
            'No other category may be renumbered when a category is moved to the end of the tree.'
        );
        $this->assertLessThanOrEqual(count($this->get_courses_of($movedid)), count($changes['courses']));
        $this->assert_invariants_hold();

        // The moved category is now the last child of its new parent and behind all other courses.
        $this->assertGreaterThan(
            (int) $DB->get_field('course_categories', 'sortorder', ['id' => $newparentid]),
            (int) $DB->get_field('course_categories', 'sortorder', ['id' => $movedid])
        );
    }

    /**
     * Moving a category into the middle of the tree renumbers the categories that follow it only.
     *
     * This is the worst case: the sortorder of a valid tree has no room for inserting a category in
     * the middle, so the categories behind the new parent have to be pushed away and their courses
     * just follow their category.
     */
    public function test_moving_a_category_into_the_middle_of_the_tree(): void {
        $this->resetAfterTest();

        $tree = $this->create_category_tree(3, 3, 2);
        $snapshot = $this->get_sortorders();
        $firstsubcategories = reset($tree);
        $movedid = reset($firstsubcategories);
        $newparentid = end($firstsubcategories);

        $this->move_category($movedid, $newparentid);
        $this->count_queries_of_fix();
        $this->assert_invariants_hold();

        $depthfirst = $this->get_depth_first_categories();
        $position = array_search($newparentid, $depthfirst, true);
        $this->assertNotFalse($position);

        $changes = $this->get_sortorder_changes($snapshot);
        $this->assertArrayHasKey($movedid, $changes['categories']);
        foreach ($depthfirst as $index => $categoryid) {
            if ($index <= $position && $categoryid !== $movedid) {
                // The categories in front of the new parent keep their sortorder.
                $this->assertArrayNotHasKey(
                    $categoryid,
                    $changes['categories'],
                    "The category {$categoryid} is in front of the new parent and must not be renumbered."
                );
            }
        }
        $this->assertLessThanOrEqual(count($depthfirst) - $position, count($changes['categories']));
    }

    /**
     * A category whose parent disappeared is moved to the top level of the tree.
     */
    public function test_dangling_parent_is_repaired(): void {
        global $DB;

        $this->resetAfterTest();

        $tree = $this->create_category_tree(2, 2, 1);
        $topid = array_key_first($tree);
        $orphanid = $tree[$topid][0];

        // Delete the parent of a category without touching the category itself.
        $DB->delete_records('course_categories', ['id' => $topid]);

        $this->count_queries_of_fix();
        $cat = $DB->get_record('course_categories', ['id' => $orphanid]);
        $this->assertSame(0, (int) $cat->parent);
        $this->assertSame(1, (int) $cat->depth);
        $this->assertSame('/' . $orphanid, $cat->path);
        $this->assert_invariants_hold();
    }
}

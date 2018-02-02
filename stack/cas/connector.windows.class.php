<?php
// This file is part of Stack - http://stack.maths.ed.ac.uk/
//
// Stack is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Stack is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Stack.  If not, see <http://www.gnu.org/licenses/>.

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/platforms.php');
/**
 * Connection to Maxima for Windows systems.
 *
 * @copyright  2012 The University of Birmingham
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stack_cas_connection_windows extends stack_cas_connection_base {

    /**
     * Connect directly to the CAS, and return the raw string result.
     *
     * @param string $command The string of CAS commands to be processed.
     * @return string|bool The raw results or FALSE if there was an error.
     * @throws stack_exception
     */
    protected function call_maxima($command) {
        set_time_limit(0); // Note, some users may not want this!
        $ret = false;

        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('file', $this->logs . "cas_errors.txt", 'a'));

        $cmd = $this->command;
        $this->debug->log('Command line', $cmd);

        $casprocess = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($casprocess)) {
            throw new stack_exception('stack_cas_connection: Could not open a CAS process.');
        }

        if (!fwrite($pipes[0], $this->initcommand)) {
            return(false);
        }
        fwrite($pipes[0], $command);
        fwrite($pipes[0], 'quit();\n\n');
        fflush($pipes[0]);

        // Read output from stdout.
        $ret = '';
        while (!feof($pipes[1])) {
            $out = fgets($pipes[1], 1024);
            if ('' == $out) {
                // Pause.
                usleep(1000);
            }
            $ret .= $out;
        }
        fclose($pipes[0]);
        fclose($pipes[1]);

        return trim($ret);
    }
}

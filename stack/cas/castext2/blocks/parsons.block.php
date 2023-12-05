<?php
// This file is part of STACK
//
// STACK is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// STACK is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with STACK.  If not, see <http://www.gnu.org/licenses/>.
defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../block.interface.php');
require_once(__DIR__ . '/../block.factory.php');

require_once(__DIR__ . '/root.specialblock.php');
require_once(__DIR__ . '/stack_translate.specialblock.php');
require_once(__DIR__ . '/../../../../vle_specific.php');

require_once(__DIR__ . '/iframe.block.php');
stack_cas_castext2_iframe::register_counter('///PARSONS_COUNT///');

class stack_cas_castext2_parsons extends stack_cas_castext2_block {

    /* This is not something we want people to edit in general. */
    public static $namedversions = [
        'cdn' => [
            'js' => 'https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js',
        ],
        'local' => [
            'css' => 'cors://sortable.min.css',
            'js' => 'cors://sortable.min.js'
        ]
    ];

    public function compile($format, $options):  ? MP_Node {
        $r = new MP_List([new MP_String('iframe')]);

        // Define iframe params.
        $xpars = [];
        $inputs = []; // From inputname to variable name.
        $clone = "false"; // Whether to have all keys in available list cloned.
        foreach ($this->params as $key => $value) {
            if ($key === 'clone') {
                $clone = $value;
            } else if ($key !== 'input') {
                $xpars[$key] = $value;
            } else {
                $inputs[$key] = $value;
            }
        }
        // These are some of the other parameters we do not need to push forward.
        if (isset($xpars['version'])) {
            unset($xpars['version']);
        }
        if (isset($xpars['overridecss'])) {
            unset($xpars['overridecss']);
        }
        if (isset($xpars['overridejs'])) {
            unset($xpars['overridejs']);
        }
        if (isset($xpars['orientation'])) {
            unset($xpars['orientation']);
        }

        // Set default width and height here.
        // We want to push forward to overwrite the iframe defaults if they are not provided in the block parameters.
        $existsuserwidth = array_key_exists('width', $xpars);
        $existsuserheight = array_key_exists('height', $xpars);
        $width = $existsuserwidth ? $xpars['width'] : "100%";
        $height = $existsuserheight ? $xpars['height'] : "400px";
        $xpars['width'] = $width;
        $xpars['height'] = $height;

        // Set a title.
        $xpars['title'] = 'STACK Parsons ///PARSONS_COUNT///';

        // Figure out what scripts we serve.
        $css = self::$namedversions['local']['css'];
        $js = self::$namedversions['local']['js'];
        if (isset($this->params['version']) &&
            isset(self::$namedversions[$this->params['version']])) {
            $css = self::$namedversions['local']['css'];
            $js = self::$namedversions[$this->params['version']]['js'];
        }
        if (isset($this->params['overridecss'])) {
            $css = $this->params['overridecss'];
        }
        if (isset($this->params['overridejs'])) {
            $js = $this->params['overridejs'];
        }

        $r->items[] = new MP_String(json_encode($xpars));

        // Plug in some style and scripts.
        $mathjax = stack_get_mathjax_url();
        $r->items[] = new MP_List([
            new MP_String('script'),
            new MP_String(json_encode(['type' => 'text/javascript', 'src' => $mathjax]))]);
        $r->items[] = new MP_List([
            new MP_String('style'),
            new MP_String(json_encode(['href' => $css]))
        ]);
        $r->items[] = new MP_List([
            new MP_String('script'),
            new MP_String(json_encode(['type' => 'text/javascript', 'src' => $js]))
        ]);

        // We need to define a size for the inner content.
        $aspectratio = false;
        $astyle = "width:calc(100% - 3px);height:calc(100% - 3px);";
        if (array_key_exists('aspect-ratio', $xpars)) {
            $aspectratio = $xpars['aspect-ratio'];
            // Unset the undefined dimension, if both are defined then we have a problem.
            if ($existsuserheight) {
                $astyle = "height:calc(100% - 3px);aspect-ratio:$aspectratio;";
            } else if ($existsuserwidth) {
                $astyle = "width:calc(100% - 3px);aspect-ratio:$aspectratio;";
            }
        }

        // Add correctly oriented container divs for the proof lists to be accessed by sortable.
        $orientation = isset($this->params['orientation']) ? $this->params['orientation'] : 'horizontal';
        $outer = $orientation === 'horizontal' ? 'row' : 'col';
        $inner = $orientation === 'horizontal' ? 'col' : 'row';
        $innerui = '<ul class="list-group ' . $inner . '" id="usedList"></ul>
                        <ul class="list-group ' . $inner . '" id="availableList"></ul>';

        $r->items[] = new MP_String("<button type='button' class='parsons-button' id='orientation'>
            <i class='fa fa-refresh'></i></button>");
        $r->items[] = new MP_String("<button type='button' class='parsons-button' id='resize'>
            <i class='fa fa-expand'></i></button>");
        if ($clone === 'true') {
            $r->items[] = new MP_String('<div class="parsons-button parsons-bin">
            <i class="fa fa-trash bin-icon"></i><div class="drop-zone" id="bin"></div></div>');
        }

        $r->items[] = new MP_String('<div class="container" id="sortableContainer" style="' . $astyle . '">
            <div class=row>' . $innerui . '
            </div>
        </div>');

        // JS script.
        $r->items[] = new MP_String('<script type="module">');

        $importcode = "\nimport {stack_js} from '" . stack_cors_link('stackjsiframe.min.js') . "';\n";
        $importcode .= "import {Sortable} from '" . stack_cors_link('sortable.min.js') . "';\n";
        $importcode .= "import {preprocess_steps, stack_sortable, add_orientation_listener, get_iframe_height} from '" .
            stack_cors_link('stacksortable.min.js') . "';\n";
        $r->items[] = new MP_String($importcode);

        // Add the resize button listener
        $r->items[] = new MP_String('document.getElementById("resize").addEventListener(
            "click", () => {stack_js.resize_containing_frame("' . $width . '", get_iframe_height() + "px");});' . "\n");
        
        // Add flip orientation listener to the orientation button
        // TODO :automatically set orientation based on device.
        $r->items[] = new MP_String('add_orientation_listener("orientation", "usedList", "availableList");' . "\n");
        // Extract the proof steps from the inner content.
        $r->items[] = new MP_String('var proofSteps = ');

        $opt2 = [];
        if ($options !== null) {
            $opt2 = array_merge([], $options);
        }
        $opt2['in iframe'] = true;

        foreach ($this->children as $item) {
            // Assume that all code inside is JavaScript.
            // Assume we do not want to do the markdown escaping or any other in it.
            $c = $item->compile(castext2_parser_utils::RAWFORMAT, $opt2);
            if ($c !== null) {
                $r->items[] = $c;
            }
        }

        // Parse steps and Sortable options separately if they exist. Invalid JSON will be identified by preprocess_steps function.
        $code = 'var headers = {used: {header: "' . stack_string('stackBlock_parsons_used_header') . '"},
        available: {header: "' . stack_string('stackBlock_parsons_available_header') . '"}};' . "\n";
        $code .= 'var sortableUserOpts = {};' . "\n";
        $code .= 'var valid;' . "\n";
        $code .= '[proofSteps, headers, sortableUserOpts, valid] = preprocess_steps(proofSteps, headers, sortableUserOpts);' . "\n";
    
        // If the author's JSON has invalid format throw an error
        $code .= 'if (valid === false) 
            {stack_js.display_error("' . stack_string('stackBlock_parsons_contents') . '");}' . "\n";

        // Link up to STACK inputs.
        if (count($inputs) > 0) {
            $code .= 'var inputPromise = stack_js.request_access_to_input("' . $this->params['input'] . '", true);';
            $code .= "\n";
            $code .= 'inputPromise.then((id) => {' . "\n";
        } else {
            $code .= 'var id;' . "\n";
        };

        // Instantiate STACK sortable helper class.
        $code .= 'const stackSortable = new stack_sortable(proofSteps, "availableList", "usedList", id, sortableUserOpts, "' .
                $clone .'");' . "\n";
        // Generate the two lists in HTML.
        $code .= 'stackSortable.add_headers(headers);' . "\n";
        $code .= 'stackSortable.generate_used();' . "\n";
        $code .= 'stackSortable.generate_available();' . "\n";

        // Create the Sortable objects.
        $code .= 'var usedOpts = {...stackSortable.options.used, ...{onSort: () => ' .
                '{stackSortable.update_state(sortableUsed, sortableAvailable);}}}' . "\n";
        $code .= 'var availableOpts = {...stackSortable.options.available, ' .
                '...{onSort: () => {stackSortable.update_state(sortableUsed, sortableAvailable);}}}' . "\n";
        $code .= 'var sortableUsed = Sortable.create(usedList, usedOpts);' . "\n";
        $code .= 'var sortableAvailable = Sortable.create(availableList, availableOpts);' . "\n";

        // Create bin for clone mode.
        if ($clone === "true") {
            $code .= 'var sortableBin = Sortable.create(bin, {group: {name: "sortableBin", pull: false, put: ' .
                '"sortableUsed"}, onAdd: (e) => {document.getElementById("bin").removeChild(e.item);}});' . "\n";
        }

        // Add double-click events.
        $code .= 'stackSortable.update_state_dblclick(sortableUsed, sortableAvailable);' . "\n";

        // Typeset MathJax.
        // TODO : MathJax 3 works with promises instead, so check for version and modify as necessary
        $code .= 'MathJax.Hub.Queue(["Typeset", MathJax.Hub]);' . "\n";

        // Resize the outer iframe if the author does not pre-define width
        // TODO : modify for Mathjax 3
        if (!$existsuserheight) {
            $code .= 'MathJax.Hub.Queue(
                () => {stack_js.resize_containing_frame("' . $width . '", get_iframe_height() + "px");}
            );' . "\n";
        }

        if (count($inputs) > 0) {
            $code .= "\n});";
        };

        $r->items[] = new MP_String($code);
        $r->items[] = new MP_String('</script>');

        return $r;
    }

    public function is_flat() : bool {
        // Even when the content were flat we need to evaluate this during postprocessing.
        return false;
    }

    public function postprocess(array $params, castext2_processor $processor): string {
        return 'This is never happening! The logic goes to [[iframe]].';
    }

    public function validate_extract_attributes(): array {
        return [];
    }

    public function validate (
        &$errors = [],
        $options = []
    ): bool {
        // Basically, check that the dimensions have units we know.
        // Also that the references make sense.
        $valid  = true;
        $width  = array_key_exists('width', $this->params) ? $this->params['width'] : '100%';
        $height = array_key_exists('height', $this->params) ? $this->params['height'] : '400px';

        // NOTE! List ordered by length. For the trimming logic.
        $validunits = ['vmin', 'vmax', 'rem', 'em', 'ex', 'px', 'cm', 'mm',
            'in', 'pt', 'pc', 'ch', 'vh', 'vw', '%'];

        $widthend   = false;
        $heightend  = false;
        $widthtrim  = $width;
        $heighttrim = $height;

        foreach ($validunits as $suffix) {
            if (!$widthend && strlen($width) >= strlen($suffix) &&
                substr($width, -strlen($suffix)) === $suffix) {
                $widthend  = true;
                $widthtrim = substr($width, 0, -strlen($suffix));
            }
            if (!$heightend && strlen($height) >= strlen($suffix) &&
                substr($height, -strlen($suffix)) === $suffix) {
                $heightend  = true;
                $heighttrim = substr($height, 0, -strlen($suffix));
            }
            if ($widthend && $heightend) {
                break;
            }
        }
        $err = [];

        if (!$widthend) {
            $valid    = false;
            $err[] = stack_string('stackBlock_parsons_width');
        } else if (!preg_match('/^[0-9]*[\.]?[0-9]+$/', $widthtrim)) {
            $valid    = false;
            $err[] = stack_string('stackBlock_parsons_width_num');
        }
        if (!$heightend) {
            $valid    = false;
            $err[] = stack_string('stackBlock_parsons_height');
        } else if (!preg_match('/^[0-9]*[\.]?[0-9]+$/', $heighttrim)) {
            $valid    = false;
            $err[] = stack_string('stackBlock_parsons_height_num');
        }

        if (array_key_exists('width', $this->params) &&
            array_key_exists('height', $this->params) &&
            array_key_exists('aspect-ratio', $this->params)) {
            $valid    = false;
            $err[] = stack_string('stackBlock_parsons_overdefined_dimension');
        }
        if (!(array_key_exists('width', $this->params) ||
            array_key_exists('height', $this->params)) &&
            array_key_exists('aspect-ratio', $this->params)) {
            $valid    = false;
            $err[] = stack_string('stackBlock_parsons_underdefined_dimension');
        }

        // Check version is only one of valid options.
        if (array_key_exists('version', $this->params) && !array_key_exists($this->params['version'],
                self::$namedversions)) {
            $valid    = false;
            $validversions = ['cdn', 'local'];
            $err[] = stack_string('stackBlock_parsons_unknown_named_version', ['version' => implode(', ',
                $validversions)]);
        }

        // Check that only valid parameters are passed to block header.
        $valids = null;
        foreach ($this->params as $key => $value) {
            if ($key !== 'width' && $key !== 'height' && $key !== 'aspect-ratio' &&
                    $key !== 'version' && $key !== 'overridecss' && $key !== 'input' &&
                    $key !== 'orientation' && $key !== 'clone') {
                $err[] = "Unknown parameter '$key' for Parson's block.";
                $valid    = false;
                if ($valids === null) {
                    $valids = ['width', 'height', 'aspect-ratio', 'version', 'overridecss',
                        'overridejs', 'input', 'orientation', 'clone'];
                    $err[] = stack_string('stackBlock_parsons_param', [
                        'param' => implode(', ', $valids)]);
                }
            }
        }

        // Wrap the old string errors with the context details.
        foreach ($err as $er) {
            $errors[] = new $options['errclass']($er, $options['context'] . '/' . $this->position['start'] . '-' .
                $this->position['end']);
        }

        return $valid;
    }
}
